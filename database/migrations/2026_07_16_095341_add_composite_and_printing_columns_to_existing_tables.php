<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. إضافة حقل تحديد الصنف التجميعي لجدول الأصناف الرئيسي
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_composite')->default(false)->after('is_dimensional');
        });

        // 2. حقن أسعار العمليات الخاصة بالمطبعة (البارز والليزر) داخل مصفوفة الأسعار
        Schema::table('item_unit_prices', function (Blueprint $table) {
            $table->decimal('embossed_price', 15, 2)->nullable()->after('price');
            $table->decimal('laser_price', 15, 2)->nullable()->after('embossed_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('is_composite');
        });

        Schema::table('item_unit_prices', function (Blueprint $table) {
            $table->dropColumn(['embossed_price', 'laser_price']);
        });
    }
};
