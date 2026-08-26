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
        Schema::create('item_price_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index(); // معرف الدفعة لتنفيذ التراجع الجماعي بضغطة واحدة
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_unit_id')->constrained('item_units')->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->decimal('old_price', 16, 4);
            $table->decimal('new_price', 16, 4);
            $table->decimal('old_cost', 16, 4)->nullable();
            $table->decimal('new_cost', 16, 4)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 16, 4)->nullable();
            $table->decimal('change_percentage', 8, 2)->nullable();
            $table->string('change_type')->default('manual'); // manual, bulk_reprice, auto_indexed, rollback
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'item_unit_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_price_histories');
    }
};