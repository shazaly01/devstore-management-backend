<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MaterialConsumption extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'material_consumptions';

    protected $fillable = [
        'store_id',
        'user_id',
        'item_id',
        'item_unit_id',
        'journal_entry_id', // [حقن حقل الربط المالي المحدث]
        'quantity',
        'notes',
    ];

    protected $casts = [
        'store_id'         => 'integer',
        'user_id'          => 'integer',
        'item_id'          => 'integer',
        'item_unit_id'     => 'integer',
        'journal_entry_id' => 'integer',
        'quantity'         => 'float',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
