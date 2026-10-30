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
        Schema::table('item_disassemblies', function (Blueprint $table) {
            $table->decimal('source_quantity', 15, 4)->change();
            $table->decimal('source_unit_cost', 18, 4)->change();
            $table->decimal('source_total_cost', 18, 4)->change();
            $table->decimal('waste_quantity', 15, 4)->default(0)->change();
        });

        Schema::table('item_disassembly_items', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->change();
            $table->decimal('expected_unit_price', 18, 4)->change();
            $table->decimal('cost_percentage', 8, 4)->change();
            $table->decimal('unit_cost', 18, 4)->change();
            $table->decimal('total_cost', 18, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_disassemblies', function (Blueprint $table) {
            $table->decimal('source_quantity', 10, 4)->change();
            $table->decimal('source_unit_cost', 10, 4)->change();
            $table->decimal('source_total_cost', 10, 4)->change();
            $table->decimal('waste_quantity', 10, 4)->default(0)->change();
        });

        Schema::table('item_disassembly_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 4)->change();
            $table->decimal('expected_unit_price', 10, 4)->change();
            $table->decimal('cost_percentage', 5, 2)->change();
            $table->decimal('unit_cost', 10, 4)->change();
            $table->decimal('total_cost', 10, 4)->change();
        });
    }
};