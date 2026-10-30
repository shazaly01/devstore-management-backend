<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemDisassemblyResource extends JsonResource
{
    /**
     * تحويل كائن المورد إلى مصفوفة قابلة للتمثيل كـ JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'document_number'     => $this->document_number,
            'disassembly_date'    => $this->disassembly_date?->format('Y-m-d'),
            'store_id'            => $this->store_id,
            'store'               => $this->whenLoaded('store', function () {
                return [
                    'id'   => $this->store->id,
                    'name' => $this->store->name,
                ];
            }),
            'source_item_id'      => $this->source_item_id,
            'source_item'         => $this->whenLoaded('sourceItem', function () {
                return [
                    'id'   => $this->sourceItem->id,
                    'name' => $this->sourceItem->name,
                ];
            }),
            'source_item_unit_id' => $this->source_item_unit_id,
            'source_item_unit'    => $this->whenLoaded('sourceItemUnit', function () {
                return [
                    'id'                => $this->sourceItemUnit->id,
                    'unit_name'         => $this->sourceItemUnit->unit?->name,
                    'conversion_factor' => (float) $this->sourceItemUnit->conversion_factor,
                ];
            }),
            'source_quantity'     => (float) $this->source_quantity,
            'source_unit_cost'    => (float) $this->source_unit_cost,
            'source_total_cost'   => (float) $this->source_total_cost,
            'waste_quantity'      => (float) $this->waste_quantity,
            'notes'               => $this->notes,
            'user_id'             => $this->user_id,
            'user'                => $this->whenLoaded('user', function () {
                return [
                    'id'   => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
            'items'               => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id'                  => $item->id,
                        'item_id'             => $item->item_id,
                        'item_name'           => $item->item?->name,
                        'item_unit_id'        => $item->item_unit_id,
                        'unit_name'           => $item->itemUnit?->unit?->name,
                        'quantity'            => (float) $item->quantity,
                        'expected_unit_price' => (float) $item->expected_unit_price,
                        'cost_percentage'     => (float) $item->cost_percentage,
                        'unit_cost'           => (float) $item->unit_cost,
                        'total_cost'          => (float) $item->total_cost,
                        'notes'               => $item->notes,
                    ];
                });
            }),
            'created_at'          => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}