<?php

namespace App\Services\Pricing;

class RoundingService
{
    /**
     * تطبيق قاعدة التقريب المحددة على السعر
     *
     * @param float $price
     * @param string $rule
     * @return float
     */
    public function apply(float $price, string $rule = 'none'): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return match ($rule) {
            'nearest_50'        => $this->roundToNearest($price, 50),
            'nearest_100'       => $this->roundToNearest($price, 100),
            'nearest_500'       => $this->roundToNearest($price, 500),
            'psychological_90'  => $this->roundPsychological($price, 90, 100),
            'psychological_900' => $this->roundPsychological($price, 900, 1000),
            default             => round($price, 2),
        };
    }

    /**
     * التقريب الرياضي لأقرب قيمة صحيحة محددة (مثل 50 أو 100 أو 500)
     */
    protected function roundToNearest(float $price, int $step): float
    {
        return (float) (round($price / $step) * $step);
    }

    /**
     * التقريب النفسي لإنهاء السعر بأرقام تجارية مميزة (مثل 90 أو 900)
     */
    protected function roundPsychological(float $price, int $ending, int $base): float
    {
        if ($price <= $ending) {
            return (float) $ending;
        }

        $remainder = fmod($price, $base);
        $baseFloor = floor($price / $base) * $base;

        if ($remainder <= $ending) {
            return (float) ($baseFloor + $ending);
        }

        return (float) ($baseFloor + $base + $ending);
    }
}