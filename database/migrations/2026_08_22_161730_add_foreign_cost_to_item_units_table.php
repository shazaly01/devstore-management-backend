<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل الهجرة لإضافة حقل التكلفة بالعملة الأجنبية
     */
    public function up(): void
    {
        Schema::table('item_units', function (Blueprint $table) {
            $table->decimal('foreign_cost', 15, 4)
                ->nullable()
                ->after('cost')
                ->comment('التكلفة بالعملة الأجنبية المرجعية المحددة للصنف');
        });
    }

    /**
     * التراجع عن الهجرة
     */
    public function down(): void
    {
        Schema::table('item_units', function (Blueprint $table) {
            $table->dropColumn('foreign_cost');
        });
    }
};