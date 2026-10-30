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
        Schema::create('item_disassemblies', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->date('disassembly_date');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('source_item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('source_item_unit_id')->constrained('item_units')->restrictOnDelete();
            $table->decimal('source_quantity', 12, 4);
            $table->decimal('source_unit_cost', 12, 4);
            $table->decimal('source_total_cost', 12, 4);
            $table->decimal('waste_quantity', 12, 4)->default(0.0000);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_disassemblies');
    }
};