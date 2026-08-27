<?php

namespace App\Services\Pricing;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemUnit;
use Illuminate\Support\Facades\DB;

class ForeignCostSetupService
{
    /**
     * توليد جدول المعاينة لتأصيل التكلفة الأجنبية بالخيارات الحسابية الثلاثة بدقة تامة
     */
    public function preview(array $filters): array
    {
        $query = Item::query()
            ->with(['category', 'units.unit', 'purchaseCurrency'])
            ->where('is_active', true);

        // فلترة الأصناف التي ليس لها تكلفة أجنبية فقط إن طُلب ذلك
        if (!empty($filters['only_missing'])) {
            $query->where(function ($q) {
                $q->whereNull('purchase_currency_id')
                  ->orWhereHas('units', function ($u) {
                      $u->whereNull('foreign_cost')->orWhere('foreign_cost', '<=', 0);
                  });
            });
        }

        // جلب التصنيف المحدد وكافة التصنيفات المتفرعة عنه بالاعتماد على حقل path الشجري
        if (!empty($filters['category_id'])) {
            $category = Category::find($filters['category_id']);
            if ($category) {
                $categoryIds = Category::where('path', 'like', $category->path . '%')
                    ->orWhere('id', $category->id)
                    ->pluck('id');
                $query->whereIn('category_id', $categoryIds);
            }
        }

        $exchangeRate = (float) ($filters['exchange_rate'] ?? 1.0);
        $method = $filters['method'] ?? 'from_cost_rate';
        $previewData = [];

        $query->chunk(100, function ($items) use (&$previewData, $filters, $exchangeRate, $method) {
            foreach ($items as $item) {
                // تحديد الوحدة الأساسية للصنف
                $baseUnit = $item->units->firstWhere('unit_id', $item->base_unit_id)
                    ?: $item->units->firstWhere('conversion_factor', 1.0)
                    ?: $item->units->first();

                $rawBaseForeignCost = null;
                if ($baseUnit) {
                    // احتساب التكلفة الأجنبية الخام للوحدة الأساسية دون تقريب مبكر للحفاظ على الدقة
                    $rawBaseForeignCost = $this->calculateRawForeignCost(
                        (float) $baseUnit->cost,
                        (float) $baseUnit->price,
                        $exchangeRate,
                        $method,
                        $filters,
                        (float) $item->profit_margin
                    );
                }

                foreach ($item->units as $itemUnit) {
                    $currentPrice = (float) $itemUnit->price;
                    $currentCost  = (float) $itemUnit->cost;
                    $factor       = (float) ($itemUnit->conversion_factor ?: 1.0);

                    // احتساب التكلفة الأجنبية المقترحة للوحدة اعتماداً على المعامل وبدقة 4 خانات
                    if ($rawBaseForeignCost !== null) {
                        $suggestedForeignCost = round($rawBaseForeignCost * $factor, 4);
                    } else {
                        $unitRawCost = $this->calculateRawForeignCost(
                            $currentCost,
                            $currentPrice,
                            $exchangeRate,
                            $method,
                            $filters,
                            (float) $item->profit_margin
                        );
                        $suggestedForeignCost = round($unitRawCost, 4);
                    }

                    $previewData[] = [
                        'item_id'                => $item->id,
                        'item_name'              => $item->name,
                        'category_name'          => $item->category ? $item->category->name : '',
                        'item_unit_id'           => $itemUnit->id,
                        'unit_name'              => $itemUnit->unit ? $itemUnit->unit->name : '',
                        'conversion_factor'      => $factor,
                        'current_cost'           => $currentCost,
                        'current_price'          => $currentPrice,
                        'current_foreign_cost'   => $itemUnit->foreign_cost !== null ? (float) $itemUnit->foreign_cost : null,
                        'suggested_foreign_cost' => $suggestedForeignCost,
                        'purchase_currency_id'   => $item->purchase_currency_id,
                        'purchase_currency_code' => $item->purchaseCurrency?->code,
                        'purchase_currency_name' => $item->purchaseCurrency?->name,
                    ];
                }
            }
        });

        return $previewData;
    }

    /**
     * احتساب القيمة الخام الدقيقة للتكلفة الأجنبية دون تقريب مسبق
     */
    protected function calculateRawForeignCost(
        float $cost,
        float $price,
        float $exchangeRate,
        string $method,
        array $filters,
        float $itemProfitMargin
    ): float {
        if ($exchangeRate <= 0) {
            return 0.0;
        }

        return match ($method) {
            // 1. استنتاج التكلفة الأجنبية من التكلفة المحلية وسعر الصرف
            'from_cost_rate' => $cost / $exchangeRate,

            // 2. بالهندسة العكسية من سعر البيع وهامش الربح الفعلي (Margin)
            'reverse_from_price_margin' => (function () use ($price, $exchangeRate, $filters, $itemProfitMargin) {
                $margin = isset($filters['profit_margin']) && $filters['profit_margin'] !== ''
                    ? (float) $filters['profit_margin']
                    : $itemProfitMargin;

                $estimatedCost = $price * (1 - ($margin / 100));
                return max(0.0, $estimatedCost / $exchangeRate);
            })(),

            // 3. بنسبة خصم مئوية مباشرة من سعر البيع
            'ratio_from_price' => (function () use ($price, $exchangeRate, $filters) {
                $marginRatio = (float) ($filters['margin_ratio'] ?? 20.0);
                $estimatedCost = $price * (1 - ($marginRatio / 100));
                return max(0.0, $estimatedCost / $exchangeRate);
            })(),

            default => $cost / $exchangeRate,
        };
    }

    /**
     * حفظ وتثبيت التكلفة الأجنبية والعملة المرجعية في قاعدة البيانات بأمان وسرعة أداء مجمعة
     */
    public function apply(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $currencyId = (int) $data['currency_id'];
            $updatedUnitsCount = 0;
            $updatedItemIds = [];

            foreach ($data['items'] as $itemData) {
                $itemUnit = ItemUnit::find($itemData['item_unit_id']);
                if ($itemUnit) {
                    $itemUnit->update([
                        'foreign_cost' => round((float) $itemData['foreign_cost'], 4),
                    ]);
                    $updatedUnitsCount++;
                    $updatedItemIds[] = $itemUnit->item_id;
                }
            }

            // تحديث العملة المرجعية للشراء للأصناف المعدلة فقط
            $uniqueItemIds = array_values(array_unique($updatedItemIds));
            if (!empty($uniqueItemIds)) {
                Item::whereIn('id', $uniqueItemIds)
                    ->update(['purchase_currency_id' => $currencyId]);
            }

            return [
                'updated_units_count' => $updatedUnitsCount,
                'updated_items_count' => count($uniqueItemIds),
            ];
        });
    }
}