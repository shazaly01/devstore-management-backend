<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل الهجرة لإزالة حقول الطباعة والقياسات وتحويل النظام لمبيعات عادية
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn([
                'length',
                'width',
                'is_designed',
                'items_per_sheet'
            ]);
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'designer_id')) {
                $table->dropForeign(['designer_id']);
                $table->dropColumn('designer_id');
            }
        });

        Schema::table('item_unit_prices', function (Blueprint $table) {
            $table->dropColumn([
                'embossed_price',
                'laser_price'
            ]);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('is_dimensional');
        });
    }

    /**
     * التراجع عن الهجرة في حال الحاجة
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->boolean('is_designed')->default(false);
            $table->integer('items_per_sheet')->default(1);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('designer_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('item_unit_prices', function (Blueprint $table) {
            $table->decimal('embossed_price', 15, 2)->default(0);
            $table->decimal('laser_price', 15, 2)->default(0);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_dimensional')->default(false);
        });
    }
};
