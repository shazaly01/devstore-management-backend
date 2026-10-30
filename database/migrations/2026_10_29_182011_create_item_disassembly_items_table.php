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
        Schema::create('item_disassembly_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_disassembly_id')->constrained('item_disassemblies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('item_unit_id')->constrained('item_units')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('expected_unit_price', 12, 4)->default(0.0000);
            $table->decimal('cost_percentage', 8, 4)->default(0.0000);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('total_cost', 12, 4);
            $table->string('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_disassembly_items');
    }
};