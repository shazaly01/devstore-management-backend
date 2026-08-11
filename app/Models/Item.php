<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\ItemComponent;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'category_path',
        'name',
        'item_type',
        'profit_margin',
        'base_unit_id',
        'is_active',
        'is_dimensional',
        'is_composite',
        'expiry_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'category_id'    => 'integer',
        'base_unit_id'   => 'integer',
        'profit_margin'  => 'float',
        'is_active'      => 'boolean',
        'is_dimensional' => 'boolean',
        'is_composite'   => 'boolean',
        'expiry_date'    => 'date',
    ];

    // =========================================================================
    // --- العلاقات المتقاطعة للنظام (System Relationships) ---
    // =========================================================================

    /**
     * الارتباط بالتصنيف الشجري التابع له الصنف
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * الارتباط بالوحدة القياسية الصغرى (قاعدة الجرد والمخزون)
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * جلب كافة الوحدات اللانهائية المرتبطة والمفردة لهذا الصنف
     */
    public function units(): HasMany
    {
        return $this->hasMany(ItemUnit::class, 'item_id');
    }

    /**
     * جلب كافة الباركودات المتعددة التابعة لهذا الصنف ووحداته
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'item_id');
    }

    /**
     * جلب مصفوفة الأسعار الكاملة الموزعة على فئات الأسعار والوحدات
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ItemUnitPrice::class, 'item_id');
    }

    /**
     * [مستورد ومحمي] جلب سجلات كميات الصنف الموزعة على كافة المخازن
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class, 'item_id');
    }

    /**
     * [مستورد ومحمي] جلب السجل التاريخي لحركات هذا الصنف
     */
    public function movements(): HasMany
    {
        return $this->hasMany(ItemMovement::class, 'item_id');
    }

    /**
     * [إضافة]: جلب قائمة المكونات والمواد الخام التابعة لهذا الصنف في حال كان تجميعياً
     */
    public function components(): HasMany
    {
        return $this->hasMany(ItemComponent::class, 'parent_item_id');
    }

    // =========================================================================
    // --- الدوال المساعدة ومحركات الحساب الذكية ---
    // =========================================================================

    /**
     * دالة للتحقق مما إذا كان الصنف عبارة عن خدمة (لا تحتاج لعمليات مخزنية)
     */
    public function isService(): bool
    {
        return $this->item_type === 'service';
    }

    /**
     * دالة للتحقق مما إذا كان الصنف منتجاً مخزنياً قابلاً للبيع والشراء والجرد
     */
    public function isProduct(): bool
    {
        return $this->item_type === 'product';
    }
}