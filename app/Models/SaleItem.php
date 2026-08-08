<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'item_id',
        'item_unit_id',
        'length',
        'width',
        'is_designed',
        'quantity',
        'items_per_sheet', // [إضافة الميزة الجديدة]: عدد القطع في الفرخ أو الورقة
        'unit_price',
        'price_type',
        'subtotal',
        'discount_amount',
        'grand_total',
    ];

    protected $casts = [
        'sale_id'         => 'integer',
        'item_id'         => 'integer',
        'item_unit_id'    => 'integer',
        'length'          => 'float',
        'width'           => 'float',
        'is_designed'     => 'boolean',
        'quantity'        => 'float',
        'items_per_sheet' => 'integer', // كاست عددي صحيح صارم لعدد القطع
        'unit_price'      => 'float',
        'price_type'      => 'string',
        'subtotal'        => 'float',
        'discount_amount' => 'float',
        'grand_total'     => 'float',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }
}
