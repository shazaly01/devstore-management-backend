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
        Schema::create('item_price_history_mains', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 50)->unique()->index();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 16, 4)->nullable();
            $table->string('change_type', 50)->index();
            $table->unsignedInteger('items_count')->default(0);
            $table->text('notes')->nullable();
            
            // بيانات وحالة التراجع
            $table->boolean('is_rolled_back')->default(false)->index();
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rollback_main_id')->nullable()->constrained('item_price_history_mains')->nullOnDelete();
            
            // المستخدم الذي أنشأ الدفعة
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_price_history_mains');
    }
};