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
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('purchase_currency_id')->nullable()->after('profit_margin')->constrained('currencies')->nullOnDelete();
            $table->string('pricing_policy')->default('manual')->after('purchase_currency_id'); // manual, auto_indexed, phased
            $table->decimal('min_margin_percentage', 8, 2)->default(0.00)->after('pricing_policy');
            $table->string('rounding_rule')->default('none')->after('min_margin_percentage'); // none, nearest_50, nearest_100, nearest_500, psychological_90, psychological_900
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['purchase_currency_id']);
            $table->dropColumn([
                'purchase_currency_id',
                'pricing_policy',
                'min_margin_percentage',
                'rounding_rule',
            ]);
        });
    }
};