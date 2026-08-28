<?php

namespace App\Http\Resources\Api\Pricing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemPriceHistoryMainResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => (int) $this->id,
            'batch_code'          => (string) $this->batch_code,
            'category_id'         => $this->category_id ? (int) $this->category_id : null,
            'category_name'       => $this->category ? (string) $this->category->name : null,
            'currency_id'         => $this->currency_id ? (int) $this->currency_id : null,
            'currency_code'       => $this->currency ? (string) $this->currency->code : null,
            'currency_name'       => $this->currency ? (string) $this->currency->name : null,
            'exchange_rate'       => $this->exchange_rate !== null ? (float) $this->exchange_rate : null,
            'change_type'         => (string) $this->change_type,
            'items_count'         => (int) $this->items_count,
            'notes'               => $this->notes ? (string) $this->notes : null,
            'is_rolled_back'      => (bool) $this->is_rolled_back,
            'rolled_back_at'      => $this->rolled_back_at ? $this->rolled_back_at->format('Y-m-d H:i:s') : null,
            'rolled_back_by'      => $this->rolled_back_by ? (int) $this->rolled_back_by : null,
            'rolled_back_by_name' => $this->rolledBackByUser ? (string) $this->rolledBackByUser->name : null,
            'rollback_main_id'    => $this->rollback_main_id ? (int) $this->rollback_main_id : null,
            'user_id'             => (int) $this->user_id,
            'user_name'           => $this->user ? (string) $this->user->name : null,
            'created_at'          => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'details'             => ItemPriceHistoryResource::collection($this->whenLoaded('details')),
        ];
    }
}