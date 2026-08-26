<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Currency extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'symbol',
        'is_default',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    /**
     * سجلات أسعار الصرف التاريخية التابعة لهذه العملة
     */
    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'currency_id');
    }

    /**
     * جلب أحدث سعر صرف مسجل لهذه العملة
     */
    public function latestExchangeRate(): HasOne
    {
        return $this->hasOne(ExchangeRate::class, 'currency_id')->latestOfMany('rate_date');
    }

    /**
     * فواتير المشتريات التي تمت بهذه العملة
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'currency_id');
    }

    /**
     * الأصناف المرتبطة بهذه العملة كعملة شراء مرجعية
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'purchase_currency_id');
    }
}