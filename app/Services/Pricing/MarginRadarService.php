<?php

namespace App\Services\Pricing;

use App\Models\Category;
use App\Models\Item;

class MarginRadarService
{
    public function __construct(
        protected PricingEngineService $pricingEngine
    ) {}

    /**
     * فحص الأصناف وتحديد القائمة المعرضة لتآكل الهوامش أو الواقعة تحت الحد الأدنى
     * مع دعم الوحدات المتعددة وتقسيم معالجة البيانات لتفادي استهلاك الذاكرة
     *
     * @param array $filters
     * @return array
     */
    public function getAtRiskItems(array $filters = []): array
    {
        $query = Item::query()
            ->with(['category', 'units.unit', 'purchaseCurrency.latestExchangeRate'])
            ->where('is_active', true);

        if (!empty($filters['category_id'])) {
            $category = Category::find($filters['category_id']);
            if ($category) {
                $categoryIds = Category::where('path', 'like', $category->path . '%')->pluck('id');
                $query->whereIn('category_id', $categoryIds);
            }
        }

        $atRiskItems = [];

        $query->chunk(100, function ($items) use (&$atRiskItems) {
            foreach ($items as $item) {
                $minMargin = (float) $item->min_margin_percentage;

                // استخراج التكلفة الأجنبية للوحدة الأساسية كمرجع
                $baseUnit = $item->units->firstWhere('unit_id', $item->base_unit_id)
                    ?: $item->units->firstWhere('conversion_factor', 1.0)
                    ?: $item->units->first();

                $baseForeignCost = ($baseUnit && $baseUnit->foreign_cost !== null)
                    ? (float) $baseUnit->foreign_cost
                    : null;

                $latestRate = ($item->purchase_currency_id && $item->purchaseCurrency)
                    ? $item->purchaseCurrency->latestExchangeRate
                    : null;
                $rateValue = $latestRate ? (float) $latestRate->rate : null;

                foreach ($item->units as $itemUnit) {
                    $currentPrice = (float) $itemUnit->price;
                    $currentCost  = (float) $itemUnit->cost;

                    // احتساب التكلفة الأجنبية: المباشرة أو المستنتجة من معامل التحويل
                    $foreignCost = null;
                    if ($item->purchase_currency_id) {
                        if ($itemUnit->foreign_cost !== null && (float) $itemUnit->foreign_cost > 0) {
                            $foreignCost = (float) $itemUnit->foreign_cost;
                        } elseif ($baseForeignCost !== null) {
                            $factor = (float) ($itemUnit->conversion_factor ?: 1.0);
                            $foreignCost = round($baseForeignCost * $factor, 4);
                        }
                    }

                    // احتساب تكلفة الاستبدال بناءً على الصرف الأخير
                    $replacementCost = $currentCost;
                    if ($foreignCost !== null && $rateValue !== null) {
                        $replacementCost = round($foreignCost * $rateValue, 2);
                    }

                    // احتساب الهامش الفعلي الحالي مقابل تكلفة الاستبدال
                    $currentMargin = $this->pricingEngine->calculateMarginPercentage($currentPrice, $currentCost);
                    $replacementMargin = $this->pricingEngine->calculateMarginPercentage($currentPrice, $replacementCost);

                    $effectiveMargin = $replacementMargin;
                    $isNegativeMargin = ($effectiveMargin <= 0) || ($currentMargin <= 0);
                    $isAtRisk = ($effectiveMargin < $minMargin) || ($currentMargin < $minMargin) || $isNegativeMargin;

                    if ($isAtRisk) {
                        $marginGap = round($minMargin - $effectiveMargin, 2);

                        $atRiskItems[] = [
                            'item_id'                   => $item->id,
                            'item_name'                 => $item->name,
                            'category_id'               => $item->category_id,
                            'category_name'             => $item->category ? $item->category->name : '',
                            'item_unit_id'              => $itemUnit->id,
                            'unit_name'                 => $itemUnit->unit ? $itemUnit->unit->name : '',
                            'conversion_factor'         => (float) $itemUnit->conversion_factor,
                            'current_price'             => $currentPrice,
                            'current_cost'              => $currentCost,
                            'replacement_cost'          => $replacementCost,
                            'current_margin_percentage' => $effectiveMargin,
                            'min_margin_percentage'     => $minMargin,
                            'margin_gap_percentage'     => $marginGap,
                            'is_negative_margin'        => $isNegativeMargin,
                            'pricing_policy'            => $item->pricing_policy ?? 'manual',
                        ];
                    }
                }
            }
        });

        return $atRiskItems;
    }
}