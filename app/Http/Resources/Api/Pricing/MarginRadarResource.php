<?php

namespace App\Http\Resources\Api\Pricing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarginRadarResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_id'                   => (int) $this['item_id'],
            'item_name'                 => (string) $this['item_name'],
            'category_id'               => (int) $this['category_id'],
            'category_name'             => (string) $this['category_name'],
            'item_unit_id'              => (int) $this['item_unit_id'],
            'unit_name'                 => (string) $this['unit_name'],
            'current_price'             => (float) $this['current_price'],
            'current_cost'              => (float) $this['current_cost'],
            'replacement_cost'          => (float) $this['replacement_cost'],
            'current_margin_percentage' => (float) $this['current_margin_percentage'],
            'min_margin_percentage'     => (float) $this['min_margin_percentage'],
            'margin_gap_percentage'     => (float) $this['margin_gap_percentage'], // مقدار التآكل أو العجز عن الحد الأدنى
            'is_negative_margin'        => (bool) ($this['current_margin_percentage'] <= 0),
            'pricing_policy'            => (string) $this['pricing_policy'],
        ];
    }
}