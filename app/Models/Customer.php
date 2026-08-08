<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'credit_limit',
        'account_id',
        'opening_balance', // حقل الرصيد الافتتاحي للتأسيس
        'current_balance', // حقل الرصيد الحالي الفيزيائي لحل مشكلة الأداء N+1
        'price_list_id',   // [الإضافة الحالية]: لربط العميل بفئة الأسعار المستهدفة A أو B
    ];

    protected $casts = [
        'credit_limit'    => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'price_list_id'   => 'integer', // [الإضافة الحالية]: لضمان عودة المعرف كرقماً صحيحاً
    ];

    /**
     * الحساب الرئيسي الإجمالي للعملاء المرتبط بهذا العميل في الشجرة
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * [الإضافة الحالية]: فئة قائمة الأسعار المرتبطة بالعميل الحالي لتحديد نمط التسعير
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    /**
     * أسطر القيود اليومية المرتبطة بهذا العميل كحساب مساعد
     */
    public function journalLines()
    {
        return $this->morphMany(JournalEntryLine::class, 'sub_ledger');
    }
}
