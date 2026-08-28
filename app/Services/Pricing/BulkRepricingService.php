<?php

namespace App\Services\Pricing;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemPriceHistory;
use App\Models\ItemPriceHistoryMain;
use App\Models\ItemUnit;
use App\Models\ItemUnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkRepricingService
{
    public function __construct(
        protected PricingEngineService $pricingEngine,
        protected RoundingService $roundingService
    ) {}

    /**
     * توليد جدول المعاينة لعملية إعادة التسعير الجماعي مع معالجة وراثة التكلفة للوحدات
     */
    public function preview(array $filters): array
    {
        $query = Item::query()
            ->with(['category', 'units.unit', 'purchaseCurrency'])
            ->where('is_active', true);

        if (!empty($filters['category_id'])) {
            $category = Category::find($filters['category_id']);
            if ($category) {
                $categoryIds = Category::where('path', 'like', $category->path . '%')
                    ->orWhere('id', $category->id)
                    ->pluck('id');
                $query->whereIn('category_id', $categoryIds);
            }
        }

        $previewData = [];
        $exchangeRate = isset($filters['exchange_rate']) ? (float) $filters['exchange_rate'] : 1.0;
        $criterionType = $filters['criterion_type'] ?? 'exchange_rate';

        $query->chunk(100, function ($items) use (&$previewData, $filters, $exchangeRate, $criterionType) {
            foreach ($items as $item) {
                $roundingRule = $filters['rounding_rule'] ?? $item->rounding_rule ?? 'none';
                $foreignCurrencyCode = $item->purchaseCurrency ? $item->purchaseCurrency->code : null;

                // استخراج التكلفة الأجنبية للوحدة الأساسية كمرجع للوحدات الفرعية
                $baseUnit = $item->units->firstWhere('unit_id', $item->base_unit_id) 
                    ?: $item->units->firstWhere('conversion_factor', 1.0) 
                    ?: $item->units->first();
                
                $baseForeignCost = ($baseUnit && $baseUnit->foreign_cost !== null) 
                    ? (float) $baseUnit->foreign_cost 
                    : null;

                // اعتماد هامش الربح المدخل بالفلتر العام أو هامش ربح الصنف الافتراضي
                $itemProfitMargin = (float) $item->profit_margin;
                $profitMargin = isset($filters['profit_margin']) && $filters['profit_margin'] !== ''
                    ? (float) $filters['profit_margin']
                    : $itemProfitMargin;

                foreach ($item->units as $itemUnit) {
                    $currentPrice = (float) $itemUnit->price;
                    $currentCost  = (float) $itemUnit->cost;

                    // احتساب التكلفة الأجنبية: إما المسجلة مباشرة على الوحدة أو المستنتجة من معامل التحويل
                    $foreignCost = null;
                    if ($item->purchase_currency_id) {
                        if ($itemUnit->foreign_cost !== null && (float) $itemUnit->foreign_cost > 0) {
                            $foreignCost = (float) $itemUnit->foreign_cost;
                        } elseif ($baseForeignCost !== null) {
                            $factor = (float) ($itemUnit->conversion_factor ?: 1.0);
                            $foreignCost = round($baseForeignCost * $factor, 4);
                        }
                    }

                    $params = [
                        'foreign_cost'  => $foreignCost ?? 0.0,
                        'exchange_rate' => $exchangeRate,
                        'profit_margin' => $profitMargin,
                        'percentage'    => isset($filters['percentage']) ? (float) $filters['percentage'] : 0.0,
                        'target_margin' => isset($filters['target_margin']) ? (float) $filters['target_margin'] : 0.0,
                    ];

                    $suggestedPrice = $this->pricingEngine->calculateSuggestedPrice(
                        $currentPrice,
                        $currentCost,
                        $criterionType,
                        $params,
                        $roundingRule
                    );

                    // احتساب التكلفة المتوقعة الجديدة بناءً على المعيار المختار
                    $expectedCost = match ($criterionType) {
                        'exchange_rate' => ($foreignCost !== null) ? round($foreignCost * $exchangeRate, 2) : $currentCost,
                        'percentage'    => round($currentCost * (1 + (((float) ($filters['percentage'] ?? 0.0)) / 100)), 2),
                        default         => $currentCost,
                    };

                    $currentMargin = $this->pricingEngine->calculateMarginPercentage($currentPrice, $currentCost);
                    $expectedMargin = $this->pricingEngine->calculateMarginPercentage($suggestedPrice, $expectedCost);

                    $previewData[] = [
                        'item_id'                    => $item->id,
                        'item_name'                  => $item->name,
                        'category_id'                => $item->category_id,
                        'category_name'              => $item->category ? $item->category->name : '',
                        'item_unit_id'               => $itemUnit->id,
                        'unit_name'                  => $itemUnit->unit ? $itemUnit->unit->name : '',
                        'conversion_factor'          => (float) $itemUnit->conversion_factor,
                        'current_price'              => $currentPrice,
                        'current_cost'               => $currentCost,
                        'reference_foreign_cost'     => $foreignCost,
                        'foreign_currency_code'      => $foreignCurrencyCode,
                        'suggested_price'            => $suggestedPrice,
                        'expected_cost'              => $expectedCost,
                        'profit_margin'              => $profitMargin,
                        'current_margin_percentage'  => $currentMargin,
                        'expected_margin_percentage' => $expectedMargin,
                        'pricing_policy'             => $item->pricing_policy ?? 'manual',
                        'rounding_rule'              => $roundingRule,
                    ];
                }
            }
        });

        return $previewData;
    }

    /**
     * تطبيق واعتماد الأسعار الجديدة مع إنشاء سجل رأس الدفعة وتوثيق الحركات التابعة لها
     */
    public function apply(array $data, int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $batchUuid = (string) Str::uuid();
            $batchCode = 'PRC-' . date('Ymd') . '-' . strtoupper(Str::random(5));
            $currencyId = $data['currency_id'] ?? null;
            $categoryId = $data['category_id'] ?? null;
            $exchangeRate = isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null;
            $changeType = $data['change_type'] ?? 'bulk_reprice';
            $notes = $data['notes'] ?? null;
            $now = now();

            // 1. إنشاء سجل رأس الدفعة المجمعة
            $batchMain = ItemPriceHistoryMain::create([
                'batch_code'    => $batchCode,
                'category_id'   => $categoryId,
                'currency_id'   => $currencyId,
                'exchange_rate' => $exchangeRate,
                'change_type'   => $changeType,
                'items_count'   => count($data['items']),
                'notes'         => $notes,
                'user_id'       => $userId,
            ]);

            $historyRecords = [];
            $updatedCount = 0;
            $itemMarginUpdates = [];

            foreach ($data['items'] as $itemData) {
                $itemUnit = ItemUnit::with('prices')->find($itemData['item_unit_id']);
                if (!$itemUnit) {
                    continue;
                }

                $oldPrice = (float) $itemUnit->price;
                $newPrice = (float) $itemData['new_price'];
                $oldCost  = isset($itemData['old_cost']) ? (float) $itemData['old_cost'] : (float) $itemUnit->cost;
                $newCost  = isset($itemData['new_cost']) ? (float) $itemData['new_cost'] : (float) $itemUnit->cost;

                // تجميع تحديثات هامش الربح الخاصة بكروت الأصناف
                if (isset($itemData['profit_margin']) && $itemData['profit_margin'] !== null) {
                    $itemMarginUpdates[$itemData['item_id']] = (float) $itemData['profit_margin'];
                }

                $changePercentage = $oldPrice > 0 
                    ? round((($newPrice - $oldPrice) / $oldPrice) * 100, 2) 
                    : 0.0;

                // 2. تجهيز سجل الوحدة للتخزين المجمع وربطه برأس الدفعة
                $historyRecords[] = [
                    'item_price_history_main_id' => $batchMain->id,
                    'batch_id'                   => $batchUuid,
                    'item_id'                    => $itemData['item_id'],
                    'item_unit_id'               => $itemData['item_unit_id'],
                    'price_list_id'              => null,
                    'old_price'                  => $oldPrice,
                    'new_price'                  => $newPrice,
                    'old_cost'                   => $oldCost,
                    'new_cost'                   => $newCost,
                    'currency_id'                => $currencyId,
                    'exchange_rate'              => $exchangeRate,
                    'change_percentage'          => $changePercentage,
                    'change_type'                => $changeType,
                    'user_id'                    => $userId,
                    'notes'                      => $notes,
                    'created_at'                 => $now,
                    'updated_at'                 => $now,
                ];

                // 3. تحديث سعر وتكلفة الوحدة
                $itemUnit->update([
                    'price' => $newPrice,
                    'cost'  => $newCost,
                ]);

                // 4. تحديث مصفوفة فئات الأسعار التابعة للوحدة وتجهيز سجلاتها
                foreach ($itemUnit->prices as $unitPrice) {
                    $oldCategoryPrice = (float) $unitPrice->price;
                    $discount = (float) $unitPrice->discount_percentage;
                    $newCategoryPrice = $discount > 0
                        ? round($newPrice * (1 - ($discount / 100)), 2)
                        : $newPrice;

                    $historyRecords[] = [
                        'item_price_history_main_id' => $batchMain->id,
                        'batch_id'                   => $batchUuid,
                        'item_id'                    => $itemData['item_id'],
                        'item_unit_id'               => $itemData['item_unit_id'],
                        'price_list_id'              => $unitPrice->price_list_id,
                        'old_price'                  => $oldCategoryPrice,
                        'new_price'                  => $newCategoryPrice,
                        'old_cost'                   => $oldCost,
                        'new_cost'                   => $newCost,
                        'currency_id'                => $currencyId,
                        'exchange_rate'              => $exchangeRate,
                        'change_percentage'          => $changePercentage,
                        'change_type'                => $changeType,
                        'user_id'                    => $userId,
                        'notes'                      => 'تحديث تلقائي تابع لفئة السعر',
                        'created_at'                 => $now,
                        'updated_at'                 => $now,
                    ];

                    $unitPrice->update([
                        'price' => $newCategoryPrice,
                    ]);
                }

                $updatedCount++;
            }

            // 5. تحديث هامش الربح في كروت الأصناف الأساسية (items)
            foreach ($itemMarginUpdates as $itemId => $margin) {
                Item::where('id', $itemId)->update(['profit_margin' => $margin]);
            }

            // 6. إدخال السجلات التاريخية مجمعة
            if (!empty($historyRecords)) {
                foreach (array_chunk($historyRecords, 250) as $chunk) {
                    ItemPriceHistory::insert($chunk);
                }
            }

            // 7. تحديث إجمالي عدد الوحدات المحدثة فعلياً في رأس الدفعة
            $batchMain->update(['items_count' => $updatedCount]);

            return [
                'main_id'       => $batchMain->id,
                'batch_code'    => $batchCode,
                'batch_id'      => $batchUuid,
                'updated_count' => $updatedCount,
            ];
        });
    }
}