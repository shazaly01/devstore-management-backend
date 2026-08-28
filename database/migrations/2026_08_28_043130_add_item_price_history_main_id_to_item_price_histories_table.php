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
        Schema::table('item_price_histories', function (Blueprint $table) {
            $table->foreignId('item_price_history_main_id')
                ->nullable()
                ->after('id')
                ->constrained('item_price_history_mains')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_price_histories', function (Blueprint $table) {
            $table->dropForeign(['item_price_history_main_id']);
            $table->dropColumn('item_price_history_main_id');
        });
    }
};