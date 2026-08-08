<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * تحويل بيانات رأس فاتورة المبيعات مع تضمين السطور التابعة لها إلى JSON جاهز للطباعة والعرض
     */
    public function toArray(Request $request): array
    {
        // مصفوفة التسميات المحدثة لضمان معالجة خيار الدفع الإلكتروني (card)
        $paymentTypeLabels = [
            'cash'   => 'نقدي',
            'card'   => 'شبكة / دفع إلكتروني',
            'credit' => 'آجل / ذمم',
        ];

        return [
            'id'               => $this->id,
            'invoice_type'     => $this->invoice_type,
            'invoice_type_lbl' => $this->invoice_type === 'sale' ? 'فاتورة مبيعات' : 'مردودات مبيعات',
            'invoice_sequence' => $this->invoice_sequence,
            'invoice_number'   => $this->invoice_number, // الكود النظيف المنسق (INV-0001 أو SR-0001)
            'parent_id'        => $this->parent_id,
            'parent_number'    => $this->parentInvoice->invoice_number ?? null, // رقم الفاتورة الأصلية في حال المرتجع

            // روابط الحسابات والقنوات اللوجستية والمالية
            'store_id'         => $this->store_id,
            'store_name'       => $this->store->name ?? null,

            'treasury_id'      => $this->treasury_id,
            'treasury_name'    => $this->treasury->name ?? null, // اسم الخزنة المستلمة ماليّاً

            'bank_id'          => $this->bank_id,
            'bank_name'        => $this->bank->name ?? null, // اسم الحساب البنكي المستلم

            'customer_id'        => $this->customer_id,
            'customer_name'      => $this->customer->name ?? null, // اسم العميل المستخرج من شجرة الحسابات
            'customer_name_text' => $this->customer_name_text,     // اسم العميل الحر
            'user_id'            => $this->user_id,
            'user_name'          => $this->user->name ?? null,     // كاتب الفاتورة / الكاشير

            // البيانات المالية والزمنية للفاتورة
            'invoice_date'     => $this->invoice_date ? $this->invoice_date->format('Y-m-d H:i:s') : null,
            'payment_type'     => $this->payment_type,
            'payment_type_lbl' => $paymentTypeLabels[$this->payment_type] ?? $this->payment_type,

            'subtotal'         => (float) $this->subtotal,
            'discount_amount'  => (float) $this->discount_amount,
            'tax_amount'       => (float) $this->tax_amount,
            'grand_total'      => (float) $this->grand_total, // الصافي النهائي
            'notes'            => $this->notes,

            // تحميل سطور تفاصيل الفاتورة تلقائياً عبر ريسورس السطور
            'items'            => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
