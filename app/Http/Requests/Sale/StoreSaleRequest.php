<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Sale;

class StoreSaleRequest extends FormRequest
{
    /**
     * التحقق من صلاحية المستخدم لإنشاء المستند
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Sale::class);
    }

    /**
     * قواعد التحقق الصارمة لإنشاء رأس وسطور المبيعات
     */
    public function rules(): array
    {
        return [
            // --- قواعد رأس الفاتورة ---
            'invoice_type'       => ['required', Rule::in(['sale', 'return'])],
            'parent_id'          => [
                'nullable',
                Rule::requiredIf($this->invoice_type === 'return'),
                'exists:sales,id'
            ],
            'store_id'           => ['required', 'exists:stores,id'],

            // الفاتورة القديمة: تعتمد شروط الخزينة والبنك على payment_type.
            // الفاتورة الجديدة المرسلة بمبالغ منفصلة: تعتمد الشروط على المبلغ المستخدم فعلياً.
            'treasury_id'        => [
                Rule::requiredIf(fn () => $this->usesSplitPaymentAmounts()
                    ? $this->paymentAmountIsPositive('cash_amount')
                    : $this->payment_type === 'cash'),
                'nullable',
                'exists:treasuries,id'
            ],
            'bank_id'            => [
                Rule::requiredIf(fn () => $this->usesSplitPaymentAmounts()
                    ? $this->paymentAmountIsPositive('bank_amount')
                    : $this->payment_type === 'card'),
                'nullable',
                'exists:banks,id'
            ],

            // معلومات العميل والتاريخ
            'customer_id'        => ['required', 'exists:customers,id'],
            'invoice_date'       => ['required', 'date'],

            // تُبقى طريقة الدفع لأجل التوافق مع الفواتير والطلبات القديمة.
            'payment_type'       => ['required', Rule::in(['cash', 'card', 'credit'])],

            // مبلغَا الدفع اختياريان لقبول الطلبات القديمة التي لا ترسلهما.
            'cash_amount'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'bank_amount'        => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // الماليات الإجمالية للرأس
            'subtotal'           => ['required', 'numeric', 'min:0'],
            'discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'tax_amount'         => ['nullable', 'numeric', 'min:0'],
            'grand_total'        => ['required', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string', 'max:1000'],

            // --- قواعد مصفوفة السطور ---
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.item_id'          => ['required', 'exists:items,id'],
            'items.*.item_unit_id'     => ['required', 'exists:item_units,id'],
            'items.*.quantity'         => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0'],
            'items.*.subtotal'          => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'items.*.grand_total'      => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * التحقق المتقدم لمراقبة كميات المرتجع ومجموع مبالغ الدفع.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validatePaymentSplit($validator);

            $items = $this->input('items', []);
            if (empty($items) || !is_array($items)) {
                return;
            }

            $parentId = $this->input('parent_id');
            $invoiceType = $this->input('invoice_type');

            // الحماية المحاسبية لمرتجعات الكميات المطلقة
            if ($invoiceType === 'return' && $parentId) {
                $parentSale = Sale::with('items')->find($parentId);

                if ($parentSale) {
                    // جلب المرتجعات السابقة المرتبطة بهذه الفاتورة الأم
                    $previousReturns = Sale::where('parent_id', $parentId)
                        ->where('invoice_type', 'return')
                        ->with('items')
                        ->get();

                    // تجميع إجمالي الكميات الأصلية المتاحة بالفاتورة الأم
                    $originalTotals = [];
                    foreach ($parentSale->items as $pItem) {
                        $key = $pItem->item_id . '-' . $pItem->item_unit_id;
                        if (!isset($originalTotals[$key])) {
                            $originalTotals[$key] = [
                                'quantity' => 0.00,
                            ];
                        }
                        $originalTotals[$key]['quantity'] += (float) $pItem->quantity;
                    }

                    // تجميع إجمالي ما تم إرجاعه مسبقاً
                    $returnedTotals = [];
                    foreach ($previousReturns as $prevReturn) {
                        foreach ($prevReturn->items as $rItem) {
                            $key = $rItem->item_id . '-' . $rItem->item_unit_id;
                            if (!isset($returnedTotals[$key])) {
                                $returnedTotals[$key] = [
                                    'quantity' => 0.00,
                                ];
                            }
                            $returnedTotals[$key]['quantity'] += (float) $rItem->quantity;
                        }
                    }

                    // مطابقة كميات المرتجع الحالي ومقارنتها بالمتبقي الفعلي المتاح
                    foreach ($items as $index => $item) {
                        $itemId = $item['item_id'] ?? null;
                        $itemUnitId = $item['item_unit_id'] ?? null;
                        $key = $itemId . '-' . $itemUnitId;

                        // التحقق من أن الصنف تم شراؤه بالفعل في الفاتورة الأصلية
                        if (!isset($originalTotals[$key])) {
                            $validator->errors()->add(
                                "items.{$index}.item_id",
                                'هذا الصنف غير موجود في سطور فاتورة المبيعات الأصلية المرجعية.'
                            );
                            continue;
                        }

                        $origQty = $originalTotals[$key]['quantity'];
                        $prevQty = $returnedTotals[$key]['quantity'] ?? 0.00;
                        $currentQty = (float) ($item['quantity'] ?? 0.00);

                        $availableQty = $origQty - $prevQty;
                        if ($currentQty > $availableQty) {
                            $validator->errors()->add(
                                "items.{$index}.quantity",
                                "الكمية المسترجعة الحالية ({$currentQty}) تتجاوز الكمية المتبقية القابلة للإرجاع في الفاتورة الأصلية ({$availableQty})."
                            );
                        }
                    }
                }
            }
        });
    }

    /**
     * يحدد ما إذا كان الطلب يرسل مبالغ منفصلة لفاتورة بيع.
     */
    protected function usesSplitPaymentAmounts(): bool
    {
        return $this->input('invoice_type') === 'sale'
            && ($this->has('cash_amount') || $this->has('bank_amount'));
    }

    /**
     * يحدد ما إذا كانت قيمة مبلغ الدفع الممرر أكبر من صفر.
     */
    protected function paymentAmountIsPositive(string $field): bool
    {
        $amount = $this->input($field);

        return is_numeric($amount) && (float) $amount > 0;
    }

    /**
     * يمنع أن يتجاوز مجموع النقد والبنك إجمالي فاتورة البيع.
     */
    protected function validatePaymentSplit($validator): void
    {
        if (!$this->usesSplitPaymentAmounts()) {
            return;
        }

        $cashInput = $this->input('cash_amount');
        $bankInput = $this->input('bank_amount');
        $grandTotalInput = $this->input('grand_total');

        // قواعد الحقول الأساسية تتولى إظهار أخطاء القيم غير الرقمية.
        if (($cashInput !== null && !is_numeric($cashInput))
            || ($bankInput !== null && !is_numeric($bankInput))
            || !is_numeric($grandTotalInput)) {
            return;
        }

        // القيمة الفارغة أو غير المرسلة تعامل كمبلغ صفر.
        $cashAmount = $cashInput === null ? 0.0 : (float) $cashInput;
        $bankAmount = $bankInput === null ? 0.0 : (float) $bankInput;
        $grandTotal = (float) $grandTotalInput;

        if (round($cashAmount + $bankAmount - $grandTotal, 2) > 0) {
            $validator->errors()->add(
                'bank_amount',
                'مجموع المبلغ النقدي والبنكي لا يجوز أن يتجاوز إجمالي الفاتورة.'
            );
        }
    }

    /**
     * تخصيص أسماء الحقول لتظهر الأخطاء واضحة للمستخدم باللغة العربية
     */
    public function attributes(): array
    {
        return [
            'invoice_type'            => 'نوع الفاتورة',
            'parent_id'               => 'فاتورة المبيعات الأصلية',
            'store_id'                => 'المخزن',
            'treasury_id'             => 'الخزنة المالية المستلمة',
            'bank_id'                 => 'الحساب البنكي المستلم',
            'cash_amount'             => 'المبلغ النقدي المدفوع',
            'bank_amount'             => 'المبلغ البنكي المدفوع',
            'customer_id'             => 'حساب العميل',
            'sale_type'               => 'نوع حركة البيع (داخلي / خارجي)',
            'customer_name_text'      => 'اسم العميل الخارجي (النصي)',
            'invoice_date'            => 'تاريخ الفاتورة',
            'payment_type'            => 'طريقة الدفع',
            'grand_total'             => 'الصافي النهائي للمبيعات',
            'items'                   => 'عناصر الفاتورة',
            'items.*.item_id'         => 'الصنف المباع',
            'items.*.item_unit_id'    => 'وحدة الصنف',
            'items.*.quantity'        => 'الكمية المباعة',
            'items.*.unit_price'      => 'سعر البيع للوحدة',
            'items.*.price_type'      => 'نوع السعر المختار',
            'items.*.grand_total'     => 'إجمالي السطر',
        ];
    }
}
