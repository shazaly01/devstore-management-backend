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
        $paymentTypeLabels = [
            'cash'   => 'نقدي',
            'card'   => 'شبكة / دفع إلكتروني',
            'credit' => 'آجل / ذمم',
        ];

        $grandTotal = (float) $this->grand_total;

        // الفواتير القديمة لا تملك مبالغ مفصلة؛ تُقرأ وفق طريقة الدفع المسجلة دون تعديل قاعدة البيانات.
        if ($this->cash_amount !== null || $this->bank_amount !== null) {
            $cashAmount = (float) ($this->cash_amount ?? 0);
            $bankAmount = (float) ($this->bank_amount ?? 0);
        } else {
            $cashAmount = $this->payment_type === 'cash' ? $grandTotal : 0.0;
            $bankAmount = $this->payment_type === 'card' ? $grandTotal : 0.0;
        }

        $remainingAmount = round(max(0.0, $grandTotal - $cashAmount - $bankAmount), 2);

        $paymentParts = [];
        if ($cashAmount > 0) {
            $paymentParts[] = 'نقدي';
        }
        if ($bankAmount > 0) {
            $paymentParts[] = 'بنكي';
        }
        if ($remainingAmount > 0) {
            $paymentParts[] = 'آجل';
        }

        $paymentSummaryLabel = !empty($paymentParts)
            ? implode(' + ', $paymentParts)
            : ($paymentTypeLabels[$this->payment_type] ?? $this->payment_type);

        return [
            'id'               => $this->id,
            'invoice_type'     => $this->invoice_type,
            'invoice_type_lbl' => $this->invoice_type === 'sale' ? 'فاتورة مبيعات' : 'مردودات مبيعات',
            'invoice_sequence' => $this->invoice_sequence,
            'invoice_number'   => $this->invoice_number,
            'parent_id'        => $this->parent_id,
            'parent_number'    => $this->parentInvoice->invoice_number ?? null,

            // روابط الحسابات والقنوات اللوجستية والمالية
            'store_id'         => $this->store_id,
            'store_name'       => $this->store->name ?? null,

            'treasury_id'      => $this->treasury_id,
            'treasury_name'    => $this->treasury->name ?? null,

            'bank_id'          => $this->bank_id,
            'bank_name'        => $this->bank->name ?? null,

            'customer_id'        => $this->customer_id,
            'customer_name'      => $this->customer->name ?? null,
            'customer_name_text' => $this->customer_name_text,
            'user_id'            => $this->user_id,
            'user_name'          => $this->user->name ?? null,

            // البيانات المالية والزمنية للفاتورة
            'invoice_date'     => $this->invoice_date ? $this->invoice_date->format('Y-m-d H:i:s') : null,
            'payment_type'     => $this->payment_type,
            'payment_type_lbl' => $paymentTypeLabels[$this->payment_type] ?? $this->payment_type,
            'payment_summary_lbl' => $paymentSummaryLabel,
            'cash_amount'      => $cashAmount,
            'bank_amount'      => $bankAmount,
            'remaining_amount' => $remainingAmount,

            'subtotal'         => (float) $this->subtotal,
            'discount_amount'  => (float) $this->discount_amount,
            'tax_amount'       => (float) $this->tax_amount,
            'grand_total'      => $grandTotal,
            'notes'            => $this->notes,

            // تحميل سطور تفاصيل الفاتورة تلقائياً عبر ريسورس السطور
            'items'            => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
