<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل الهجرة لإضافة حقل الـ uuid الفريد لفواتير المبيعات.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    /**
     * التراجع عن الهجرة وإزالة الحقل.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};