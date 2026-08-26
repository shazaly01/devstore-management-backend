<?php

namespace App\Services\Pricing;

class PricingEngineService
{
    public function __construct(
        protected RoundingService $roundingService
    ) {}

    /**
     * احتساب السعر المقترح بناءً على المعيار المحدد وقاعدة التقريب
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
                (float) ($params['profit_margin'] ?? 0.0)
            ),
            'percentage' => $this->calculateByPercentage(
                $currentPrice,
                (float) ($params['percentage'] ?? 0.0)
            ),
            'target_margin' => $this->calculateByTargetMargin(
                $cost,
                (float) ($params['target_margin'] ?? 0.0)
            ),
            default => $currentPrice,
        };

        return $this->roundingService->apply($suggestedPrice, $roundingRule);
    }

    /**
     * احتساب السعر بمعيار سعر الصرف: (التكلفة الأجنبية × سعر الصرف) × (1 + هامش الربح / 100)
     */
    public function calculateByExchangeRate(float $foreignCost, float $exchangeRate, float $profitMargin = 0.0): float
    {
        $baseCostInLocal = $foreignCost * $exchangeRate;

        if ($profitMargin > 0) {
            return $baseCostInLocal * (1 + ($profitMargin / 100));
        }

        return $baseCostInLocal;
    }

    /**
     * احتساب السعر بمعيار النسبة المئوية: السعر الحالي × (1 + نسبة التغير / 100)
     */
    public function calculateByPercentage(float $currentPrice, float $percentage): float
    {
        return $currentPrice * (1 + ($percentage / 100));
    }

    /**
     * احتساب السعر بمعيار هامش الربح المستهدف (Markup على التكلفة): التكلفة × (1 + هامش الربح المستهدف / 100)
     */
    public function calculateByTargetMargin(float $cost, float $targetMargin): float
    {
        return $cost * (1 + ($targetMargin / 100));
    }

    /**
     * احتساب نسبة الإضافة / هامش الربح على التكلفة (Markup on Cost)
     * المعادلة: ((السعر - التكلفة) / التكلفة) * 100
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
     * المعادلة: ((السعر - التكلفة) / السعر) * 100
     */
    public function calculateGrossMarginPercentage(float $price, float $cost): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return round((($price - $cost) / $price) * 100, 2);
    }
}