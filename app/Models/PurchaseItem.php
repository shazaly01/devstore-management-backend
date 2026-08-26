<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'item_id',
        'item_unit_id',
        'quantity',
        'unit_cost',
        'foreign_unit_cost',
        'selling_price',
        'profit_margin',
        'expiry_date',
        'subtotal',
        'discount_amount',
        'grand_total',
    ];

    protected $casts = [
        'purchase_id'       => 'integer',
        'item_id'           => 'integer',
        'item_unit_id'      => 'integer',
        'quantity'          => 'float',
        'unit_cost'         => 'float',
        'foreign_unit_cost' => 'float',
        'selling_price'     => 'float',
        'profit_margin'     => 'float',
        'expiry_date'       => 'date',
        'subtotal'          => 'float',
        'discount_amount'   => 'float',
        'grand_total'       => 'float',
    ];

    /**
     * ارتباط سطر التفاصيل برأس الفاتورة الأساسي
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /**
     * ارتباط السطر بالصنف المحدد من دليل الأصناف
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * ارتباط السطر بوحدة الصنف المحددة من مصفوفة الوحدات
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }
}