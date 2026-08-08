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
        Schema::create('item_components', function (Blueprint $table) {
            $table->id();
            // الصنف الأب التجميعي
            $table->foreignId('parent_item_id')->constrained('items')->onDelete('cascade');
            // الصنف الابن (المادة الخام أو المكون المخزني الفعلي)
            $table->foreignId('child_item_id')->constrained('items')->onDelete('cascade');
            // الكمية المطلوبة بدقة 3 أرقام عشرية لتغطية الأمتار والأوزان الدقيقة
            $table->decimal('quantity', 10, 3);
            $table->timestamps();
            $table->softDeletes(); // حماية السجلات التاريخية لحركات كرت الصنف

            // فهارس مخصصة لضمان سرعة الاستعلام والربط أثناء عمليات البيع والخصم المخزني الكثيف
            $table->index(['parent_item_id', 'child_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_components');
    }
};
