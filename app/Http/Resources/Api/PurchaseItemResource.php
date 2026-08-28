<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class PurchaseItemResource extends JsonResource
{
    /**
     * تحويل مصفوفة السطور إلى تنسيق JSON متناسق للواجهة الأمامية مع حقن التكلفة الأجنبية والوحدات والمخزون
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'purchase_id'       => $this->purchase_id,
            'item_id'           => $this->item_id,
            'item_name'         => $this->item->name ?? null,
            'item_code'         => $this->item->code ?? null,
            'item_type'         => $this->item->item_type ?? null,

            // المعرف الخاص بمصفوفة وحدات الصنف
            'item_unit_id'      => $this->item_unit_id,
            'unit_name'         => $this->itemUnit?->unit?->name ?? null,

            'quantity'          => (float) $this->quantity,
            'unit_cost'         => (float) $this->unit_cost,
            'foreign_unit_cost' => $this->foreign_unit_cost !== null ? (float) $this->foreign_unit_cost : null,
            'profit_margin'     => (float) ($this->profit_margin ?? 0),
            'selling_price'     => $this->selling_price !== null ? (float) $this->selling_price : null,
            'expiry_date'       => $this->expiry_date ? Carbon::parse($this->expiry_date)->format('Y-m-d') : null,
            'subtotal'          => (float) $this->subtotal,
            'discount_amount'   => (float) $this->discount_amount,
            'grand_total'       => (float) $this->grand_total,

            // الرصيد المخزني الفعلي للصنف في مستودع الفاتورة
            'current_stock'     => (float) ($this->item->stocks->where('store_id', $this->purchase->store_id)->first()?->current_quantity ?? 0),

            // مصفوفة الوحدات البديلة المتاحة للصنف مع معاملات التحويل والتسعير والتكلفة الأجنبية
            'available_units'   => $this->item->units->map(function ($itemUnit) {
                return [
                    'id'                => $itemUnit->id,
                    'unit_id'           => $itemUnit->unit_id,
                    'unit_name'         => $itemUnit->unit?->name ?? null,
                    'conversion_factor' => (float) $itemUnit->conversion_factor,
                    'cost'              => (float) $itemUnit->cost,
                    'foreign_cost'      => $itemUnit->foreign_cost !== null ? (float) $itemUnit->foreign_cost : null,
                    'price'             => (float) $itemUnit->price,
                ];
            }),
        ];
    }
}