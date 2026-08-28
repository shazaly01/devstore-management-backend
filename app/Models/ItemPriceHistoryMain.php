<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ItemPriceHistoryMain extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'batch_code',
        'category_id',
        'currency_id',
        'exchange_rate',
        'change_type',
        'items_count',
        'notes',
        'is_rolled_back',
        'rolled_back_at',
        'rolled_back_by',
        'rollback_main_id',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'category_id'      => 'integer',
        'currency_id'      => 'integer',
        'exchange_rate'    => 'float',
        'items_count'      => 'integer',
        'is_rolled_back'   => 'boolean',
        'rolled_back_at'   => 'datetime',
        'rolled_back_by'   => 'integer',
        'rollback_main_id' => 'integer',
        'user_id'          => 'integer',
    ];

    /**
     * تفاصيل حركات الأسعار التابعة لهذه المجموعة
     */
    public function details(): HasMany
    {
        return $this->hasMany(ItemPriceHistory::class, 'item_price_history_main_id');
    }

    /**
     * التصنيف المستهدف في هذه الدفعة إن وجد
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * العملة المعتمدة وقت تطبيق الدفعة
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * المستخدم المنشئ للدفعة
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * المستخدم الذي قام بعملية التراجع عن هذه الدفعة
     */
    public function rolledBackByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }

    /**
     * دفعة التراجع المرتبطة بهذه الدفعة الأصلية
     */
    public function rollbackMain(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_main_id');
    }

    /**
     * الدفعة الأصلية في حال كانت هذه الدفعة هي دفعة تراجع
     */
    public function originalBatch(): HasOne
    {
        return $this->hasOne(self::class, 'rollback_main_id');
    }
}