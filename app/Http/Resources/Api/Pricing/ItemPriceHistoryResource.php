<?php

namespace App\Http\Resources\Api\Pricing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemPriceHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => (int) $this->id,
            'batch_id'          => (string) $this->batch_id,
            'item_id'           => (int) $this->item_id,
            'item_name'         => (string) ($this->item ? $this->item->name : ''),
            'item_unit_id'      => (int) $this->item_unit_id,
            'unit_name'         => (string) ($this->itemUnit && $this->itemUnit->unit ? $this->itemUnit->unit->name : ''),
            'price_list_id'     => $this->price_list_id ? (int) $this->price_list_id : null,
            'price_list_name'   => $this->priceList ? (string) $this->priceList->name : null,
            'old_price'         => (float) $this->old_price,
            'new_price'         => (float) $this->new_price,
            'old_cost'          => $this->old_cost !== null ? (float) $this->old_cost : null,
            'new_cost'          => $this->new_cost !== null ? (float) $this->new_cost : null,
            'currency_id'       => $this->currency_id ? (int) $this->currency_id : null,
            'currency_code'     => $this->currency ? (string) $this->currency->code : null,
            'exchange_rate'     => $this->exchange_rate !== null ? (float) $this->exchange_rate : null,
            'change_percentage' => $this->change_percentage !== null ? (float) $this->change_percentage : null,
            'change_type'       => (string) $this->change_type,
            'user_id'           => $this->user_id ? (int) $this->user_id : null,
            'user_name'         => $this->user ? (string) $this->user->name : null,
            'notes'             => $this->notes ? (string) $this->notes : null,
            'created_at'        => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
        ];
    }
}