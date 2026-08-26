<?php

namespace App\Services\Pricing;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\ItemUnitPrice;
use App\Models\ItemPriceHistory;
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
                $categoryIds = Category::where('path', 'like', $category->path . '%')->pluck('id');
                $query->whereIn('category_id', $categoryIds);
            }
        }

        $previewData = [];
        $exchangeRate = isset($filters['exchange_rate']) ? (float) $filters['exchange_rate'] : 1.0;
        $criterionType = $filters['criterion_type'] ?? 'exchange_rate';

        // استخدام chunk لتفادي استهلاك الذاكرة في قواعد البيانات الكبيرة
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
                        'profit_margin' => (float) $item->profit_margin,
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

                    // احتساب التكلفة المتوقعة الجديدة بناءً على الصرف إن وجد
                    $expectedCost = ($criterionType === 'exchange_rate' && $foreignCost !== null)
                        ? round($foreignCost * $exchangeRate, 2)
                        : $currentCost;

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
     * تطبيق واعتماد الأسعار الجديدة مع توثيق الحركات دفعة واحدة وتحديث التكاليف وفئات الأسعار
     */
    public function apply(array $data, int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $batchId = (string) Str::uuid();
            $currencyId = $data['currency_id'] ?? null;
            $exchangeRate = isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null;
            $changeType = $data['change_type'] ?? 'bulk_reprice';
            $notes = $data['notes'] ?? null;
            $now = now();

            $historyRecords = [];
            $updatedCount = 0;

            foreach ($data['items'] as $itemData) {
                $itemUnit = ItemUnit::with('prices')->find($itemData['item_unit_id']);
                if (!$itemUnit) {
                    continue;
                }

                $oldPrice = (float) $itemUnit->price;
                $newPrice = (float) $itemData['new_price'];
                $oldCost  = isset($itemData['old_cost']) ? (float) $itemData['old_cost'] : (float) $itemUnit->cost;
                $newCost  = isset($itemData['new_cost']) ? (float) $itemData['new_cost'] : (float) $itemUnit->cost;
                $newForeignCost = isset($itemData['new_foreign_cost']) 
                    ? (float) $itemData['new_foreign_cost'] 
                    : $itemUnit->foreign_cost;

                $changePercentage = $oldPrice > 0 
                    ? round((($newPrice - $oldPrice) / $oldPrice) * 100, 2) 
                    : 0.0;

                // 1. تجهيز سجل الوحدة الأساسية للتخزين المجمع
                $historyRecords[] = [
                    'batch_id'          => $batchId,
                    'item_id'           => $itemData['item_id'],
                    'item_unit_id'      => $itemData['item_unit_id'],
                    'price_list_id'     => null,
                    'old_price'         => $oldPrice,
                    'new_price'         => $newPrice,
                    'old_cost'          => $oldCost,
                    'new_cost'          => $newCost,
                    'currency_id'       => $currencyId,
                    'exchange_rate'     => $exchangeRate,
                    'change_percentage' => $changePercentage,
                    'change_type'       => $changeType,
                    'user_id'           => $userId,
                    'notes'             => $notes,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];

                // 2. تحديث سعر وتكلفة الوحدة
                $itemUnit->update([
                    'price'        => $newPrice,
                    'cost'         => $newCost,
                    'foreign_cost' => $newForeignCost,
                ]);

                // 3. تحديث مصفوفة فئات الأسعار التابعة للوحدة وتجهيز سجلاتها
                foreach ($itemUnit->prices as $unitPrice) {
                    $oldCategoryPrice = (float) $unitPrice->price;
                    $discount = (float) $unitPrice->discount_percentage;
                    $newCategoryPrice = $discount > 0
                        ? round($newPrice * (1 - ($discount / 100)), 2)
                        : $newPrice;

                    $historyRecords[] = [
                        'batch_id'          => $batchId,
                        'item_id'           => $itemData['item_id'],
                        'item_unit_id'      => $itemData['item_unit_id'],
                        'price_list_id'     => $unitPrice->price_list_id,
                        'old_price'         => $oldCategoryPrice,
                        'new_price'         => $newCategoryPrice,
                        'old_cost'          => $oldCost,
                        'new_cost'          => $newCost,
                        'currency_id'       => $currencyId,
                        'exchange_rate'     => $exchangeRate,
                        'change_percentage' => $changePercentage,
                        'change_type'       => $changeType,
                        'user_id'           => $userId,
                        'notes'             => 'تحديث تلقائي تابع لفئة السعر',
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];

                    $unitPrice->update([
                        'price' => $newCategoryPrice,
                    ]);
                }

                $updatedCount++;
            }

            // إدخال السجلات التاريخية مجمعة لتحقيق أقصى أداء
            if (!empty($historyRecords)) {
                foreach (array_chunk($historyRecords, 250) as $chunk) {
                    ItemPriceHistory::insert($chunk);
                }
            }

            return [
                'batch_id'      => $batchId,
                'updated_count' => $updatedCount,
            ];
        });
    }
}