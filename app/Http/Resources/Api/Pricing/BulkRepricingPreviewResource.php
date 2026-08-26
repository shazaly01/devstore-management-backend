<?php

namespace App\Http\Resources\Api\Pricing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BulkRepricingPreviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_id'                    => (int) $this['item_id'],
            'item_name'                  => (string) $this['item_name'],
            'category_id'                => (int) $this['category_id'],
            'category_name'              => (string) $this['category_name'],
            'item_unit_id'               => (int) $this['item_unit_id'],
            'unit_name'                  => (string) $this['unit_name'],
            'current_price'              => (float) $this['current_price'],
            'current_cost'               => (float) $this['current_cost'],
            'reference_foreign_cost'     => isset($this['reference_foreign_cost']) ? (float) $this['reference_foreign_cost'] : null,
            'foreign_currency_code'      => isset($this['foreign_currency_code']) ? (string) $this['foreign_currency_code'] : null,
            'suggested_price'            => (float) $this['suggested_price'],
            'current_margin_percentage'  => (float) $this['current_margin_percentage'],
            'expected_margin_percentage' => (float) $this['expected_margin_percentage'],
            'pricing_policy'             => (string) $this['pricing_policy'],
            'rounding_rule'              => (string) $this['rounding_rule'],
        ];
    }
}