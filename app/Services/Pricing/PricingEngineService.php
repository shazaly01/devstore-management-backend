<?php

namespace App\Services\Pricing;

class PricingEngineService
{
    public function __construct(
        protected RoundingService $roundingService
    ) {}

    /**
     * احتساب السعر المقترح بناءً على المعيار المحدد وقاعدة التقريب
     * مع ضمان عدم تصفير السعر في حال عدم توفر التكلفة للصنف
     */
    public function calculateSuggestedPrice(
        float $currentPrice,
        float $cost,
        string $criterionType,
        array $params = [],
        string $roundingRule = 'none'
    ): float {
        $suggestedPrice = match ($criterionType) {
            'exchange_rate' => $this->calculateByExchangeRate(
                (float) ($params['foreign_cost'] ?? 0.0),
                (float) ($params['exchange_rate'] ?? 1.0),
                (float) ($params['profit_margin'] ?? 0.0),
                $currentPrice
            ),
            'percentage' => $this->calculateByCostPercentage(
                $cost,
                (float) ($params['percentage'] ?? 0.0),
                (float) ($params['profit_margin'] ?? 0.0),
                $currentPrice
            ),
            'target_margin' => $this->calculateByTargetMargin(
                $cost,
                (float) ($params['target_margin'] ?? 0.0),
                $currentPrice
            ),
            default => $currentPrice,
        };

        // حماية نهائية: إذا كان السعر المحسوب صفراً أو سالباً، يتم الإبقاء على السعر الحالي
        if ($suggestedPrice <= 0 && $currentPrice > 0) {
            $suggestedPrice = $currentPrice;
        }

        return $this->roundingService->apply($suggestedPrice, $roundingRule);
    }

    /**
     * احتساب السعر بمعيار سعر الصرف: (التكلفة الأجنبية × سعر الصرف) × (1 + هامش الربح / 100)
     * في حال عدم توفر التكلفة الأجنبية، يتم الحفاظ على السعر الحالي
     */
    public function calculateByExchangeRate(
        float $foreignCost,
        float $exchangeRate,
        float $profitMargin = 0.0,
        float $fallbackPrice = 0.0
    ): float {
        if ($foreignCost <= 0 || $exchangeRate <= 0) {
            return $fallbackPrice;
        }

        $baseCostInLocal = $foreignCost * $exchangeRate;

        if ($profitMargin > 0) {
            return $baseCostInLocal * (1 + ($profitMargin / 100));
        }

        return $baseCostInLocal;
    }

    /**
     * احتساب السعر بمعيار نسبة تضخم التكلفة: (التكلفة الحالية × (1 + نسبة التضخم / 100)) × (1 + هامش الربح / 100)
     * في حال كانت التكلفة صفراً، يتم الحفاظ على السعر الحالي
     */
    public function calculateByCostPercentage(
        float $cost,
        float $percentage,
        float $profitMargin = 0.0,
        float $fallbackPrice = 0.0
    ): float {
        if ($cost <= 0) {
            return $fallbackPrice;
        }

        $newCost = $cost * (1 + ($percentage / 100));

        if ($profitMargin > 0) {
            return $newCost * (1 + ($profitMargin / 100));
        }

        return $newCost;
    }

    /**
     * احتساب السعر بمعيار هامش الربح المستهدف: التكلفة × (1 + هامش الربح المستهدف / 100)
     * في حال كانت التكلفة صفراً، يتم الحفاظ على السعر الحالي
     */
    public function calculateByTargetMargin(
        float $cost,
        float $targetMargin,
        float $fallbackPrice = 0.0
    ): float {
        if ($cost <= 0) {
            return $fallbackPrice;
        }

        return $cost * (1 + ($targetMargin / 100));
    }

    /**
     * احتساب نسبة الإضافة / هامش الربح على التكلفة (Markup on Cost)
     */
    public function calculateMarginPercentage(float $price, float $cost): float
    {
        if ($cost <= 0) {
            return 0.0;
        }

        return round((($price - $cost) / $cost) * 100, 2);
    }

    /**
     * احتساب هامش الربح الإجمالي من سعر البيع (Gross Margin on Selling Price)
     */
    public function calculateGrossMarginPercentage(float $price, float $cost): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return round((($price - $cost) / $price) * 100, 2);
    }
}