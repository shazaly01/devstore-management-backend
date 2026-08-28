<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ItemPriceHistory extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'item_price_history_main_id',
        'batch_id',
        'item_id',
        'item_unit_id',
        'price_list_id',
        'old_price',
        'new_price',
        'old_cost',
        'new_cost',
        'currency_id',
        'exchange_rate',
        'change_percentage',
        'change_type',
        'user_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'item_price_history_main_id' => 'integer',
        'batch_id'                   => 'string',
        'item_id'                    => 'integer',
        'item_unit_id'               => 'integer',
        'price_list_id'              => 'integer',
        'old_price'                  => 'float',
        'new_price'                  => 'float',
        'old_cost'                   => 'float',
        'new_cost'                   => 'float',
        'currency_id'                => 'integer',
        'exchange_rate'              => 'float',
        'change_percentage'          => 'float',
        'user_id'                    => 'integer',
    ];

    /**
     * الارتباط بسجل رأس الدفعة المجمعة
     */
    public function main(): BelongsTo
    {
        return $this->belongsTo(ItemPriceHistoryMain::class, 'item_price_history_main_id');
    }

    /**
     * الارتباط بالصنف
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * الارتباط بوحدة الصنف المحددة
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }

    /**
     * الارتباط بقائمة فئة السعر (إن وجدت)
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    /**
     * الارتباط بالعملة المعتمدة وقت التسعير
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * الارتباط بالمستخدم الذي أجرى التعديل
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}