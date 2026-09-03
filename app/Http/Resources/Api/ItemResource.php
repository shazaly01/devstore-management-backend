<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // التقاط معرف المخزن الحالي المرسل من فلاتر الواجهة الأمامية
        $currentStoreId = $request->input('store_id');
        $minMargin = (float) $this->min_margin_percentage;

        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'item_type'              => $this->item_type,
            'profit_margin'          => (float) $this->profit_margin,
            'purchase_currency_id'   => $this->purchase_currency_id,
            'purchase_currency_name' => $this->purchaseCurrency?->name,
            'purchase_currency_code' => $this->purchaseCurrency?->code,
            'pricing_policy'         => $this->pricing_policy ?? 'manual',
            'min_margin_percentage'  => $minMargin,
            'rounding_rule'          => $this->rounding_rule ?? 'none',
            'category_id'            => $this->category_id,
            'category_path'          => $this->category_path,
            'category_name'          => $this->category?->name,
            'base_unit_id'           => $this->base_unit_id,
            'base_unit_name'         => $this->baseUnit?->name,
            'is_active'              => (bool) $this->is_active,
            'is_composite'           => (bool) $this->is_composite,
            'expiry_date'            => $this->expiry_date?->format('Y-m-d'),
            'created_at'             => $this->created_at?->format('Y-m-d H:i:s'),

            // حقن المخزون اللحظي الفعلي بناءً على المخزن المحدد في طلب الفلترة الحالي
            'current_stock'  => (float) ($this->stocks->where('store_id', $currentStoreId)->first()?->current_quantity ?? 0),

            // حقن حد الطلب الفعلي المخصص لهذا المخزن بالتحديد لإطلاقه في شاشات التنبيه
            'reorder_level'   => (float) ($this->stocks->where('store_id', $currentStoreId)->first()?->reorder_level ?? 0),

            // شحن قائمة المكونات التفصيلية فقط إذا كان الصنف تجميعياً والعلاقة محملة مسبقاً لتفادي الـ N+1
            'components' => $this->when($this->is_composite && $this->relationLoaded('components'), function () {
                return $this->components->map(function ($component) {
                    return [
                        'id'              => $component->id,
                        'child_item_id'   => $component->child_item_id,
                        'child_item_name' => $component->childItem?->name,
                        'quantity'        => (float) $component->quantity,
                    ];
                });
            }),

            // تجميع الهيكل الشجري اللانهائي والمصفوفة السعرية بالكامل للفرونت إند
            'units' => $this->units->map(function ($itemUnit) use ($minMargin) {
                $cost = (float) $itemUnit->cost;
                $minAllowedPrice = round($cost * (1 + ($minMargin / 100)), 2);

                return [
                    'id'                => $itemUnit->id,
                    'unit_id'           => $itemUnit->unit_id,
                    'unit_name'         => $itemUnit->unit?->name,
                    'conversion_factor' => (float) $itemUnit->conversion_factor,
                    'cost'              => $cost,
                    'foreign_cost'      => $itemUnit->foreign_cost !== null ? (float) $itemUnit->foreign_cost : null,
                    'price'             => (float) $itemUnit->price,
                    'min_allowed_price' => $minAllowedPrice,

                    // الباركودات اللانهائية التابعة لهذه الوحدة بالتحديد
                    'barcodes' => $itemUnit->barcodes->pluck('barcode'),

                    // مصفوفة الأسعار والخصومات التابعة لهذه الوحدة (Pricing Matrix)
                    'prices' => $itemUnit->prices->map(function ($unitPrice) {
                        return [
                            'id'                  => $unitPrice->id,
                            'price_list_id'       => $unitPrice->price_list_id,
                            'price_list_name'     => $unitPrice->priceList?->name,
                            'discount_percentage' => (float) $unitPrice->discount_percentage,
                            'price'               => (float) $unitPrice->price,
                        ];
                    }),
                ];
            }),
        ];
    }
}