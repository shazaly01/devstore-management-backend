<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemDisassemblyItem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'item_disassembly_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'item_disassembly_id',
        'item_id',
        'item_unit_id',
        'quantity',
        'expected_unit_price',
        'cost_percentage',
        'unit_cost',
        'total_cost',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'item_disassembly_id' => 'integer',
        'item_id'             => 'integer',
        'item_unit_id'        => 'integer',
        'quantity'            => 'float',
        'expected_unit_price' => 'float',
        'cost_percentage'     => 'float',
        'unit_cost'           => 'float',
        'total_cost'          => 'float',
    ];

    /**
     * الارتباط برأس مستند التفكيك
     */
    public function disassembly(): BelongsTo
    {
        return $this->belongsTo(ItemDisassembly::class, 'item_disassembly_id');
    }

    /**
     * الارتباط بالصنف الناتج المستخرج (صدور، أوراك، إلخ)
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * الارتباط بوحدة الصنف الناتج
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }
}