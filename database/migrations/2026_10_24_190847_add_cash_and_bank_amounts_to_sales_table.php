<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة حقلي مبلغ النقد ومبلغ البنك إلى جدول المبيعات.
     *
     * الحقول اختيارية حتى تبقى الفواتير القديمة كما هي دون تعديل بياناتها.
     * القيمة null تعني أن الفاتورة أُنشئت قبل تفعيل توزيع مبالغ السداد.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('cash_amount', 15, 2)
                ->nullable()
                ->comment('الجزء المقبوض نقداً؛ وتكون القيمة null للفواتير القديمة');

            $table->decimal('bank_amount', 15, 2)
                ->nullable()
                ->comment('الجزء المقبوض عبر البنك؛ وتكون القيمة null للفواتير القديمة');
        });
    }

    /**
     * حذف حقلي توزيع مبالغ السداد عند التراجع عن الهجرة.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['cash_amount', 'bank_amount']);
        });
    }
};
