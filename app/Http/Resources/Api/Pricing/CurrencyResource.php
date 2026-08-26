<?php

namespace App\Http\Resources\Api\Pricing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => (int) $this->id,
            'name'                 => (string) $this->name,
            'code'                 => (string) $this->code,
            'symbol'               => (string) $this->symbol,
            'is_default'           => (bool) $this->is_default,
            'is_active'            => (bool) $this->is_active,
            'latest_exchange_rate' => $this->latestExchangeRate ? [
                'rate'      => (float) $this->latestExchangeRate->rate,
                'rate_date' => $this->latestExchangeRate->rate_date ? $this->latestExchangeRate->rate_date->format('Y-m-d') : null,
            ] : null,
        ];
    }
}