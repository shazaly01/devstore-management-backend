<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemDisassembly extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'item_disassemblies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'document_number',
        'disassembly_date',
        'store_id',
        'source_item_id',
        'source_item_unit_id',
        'source_quantity',
        'source_unit_cost',
        'source_total_cost',
        'waste_quantity',
        'user_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'disassembly_date'    => 'date',
        'store_id'            => 'integer',
        'source_item_id'      => 'integer',
        'source_item_unit_id' => 'integer',
        'source_quantity'     => 'float',
        'source_unit_cost'    => 'float',
        'source_total_cost'   => 'float',
        'waste_quantity'      => 'float',
        'user_id'             => 'integer',
    ];

    /**
     * توليد الرقم التسلسلي للمستند تلقائياً عند الإنشاء
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($disassembly) {
            if (empty($disassembly->document_number)) {
                $lastId = self::withTrashed()->max('id') ?? 0;
                $nextSequence = $lastId + 1;
                $disassembly->document_number = 'DIS-' . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * الارتباط بالمستودع الذي تمت فيه عملية التفكيك
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * الارتباط بالصنف الخام المصروف (دجاج كامل)
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'source_item_id');
    }

    /**
     * الارتباط بوحدة الصنف الخام المستهلك
     */
    public function sourceItemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'source_item_unit_id');
    }

    /**
     * الارتباط بالمستخدم الذي قام بتسجيل إذن التفكيك
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * جلب قائمة الأصناف والأجزاء الناتجة عن عملية التفكيك
     */
    public function items(): HasMany
    {
        return $this->hasMany(ItemDisassemblyItem::class, 'item_disassembly_id');
    }
}