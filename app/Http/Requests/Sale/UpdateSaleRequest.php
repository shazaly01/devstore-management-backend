<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Sale;

class UpdateSaleRequest extends FormRequest
{
    /**
     * التحقق من صلاحية المستخدم لتحديث المستند بناءً على السياسات الأمنية للـ API
     */
    public function authorize(): bool
    {
        $sale = $this->route('sale');
        return $sale && $this->user()->can('update', $sale);
    }

    /**
     * قواعد التحقق الصارمة لتحديث رأس وسطور المبيعات
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

            // حقن واشتراط الخزنة ماليّاً فقط في حال كان الدفع نقداً
            'treasury_id'        => [
                Rule::requiredIf($this->payment_type === 'cash'),
                'nullable',
                'exists:treasuries,id'
            ],

            // حقن واشتراط الحساب البنكي ماليّاً فقط في حال كان الدفع شبكة
            'bank_id'            => [
                Rule::requiredIf($this->payment_type === 'card'),
                'nullable',
                'exists:banks,id'
            ],

            'customer_id'        => ['required', 'exists:customers,id'],
            'invoice_date'       => ['required', 'date'],

            // تحديث طريقة الدفع لتقبل نوع الشبكة/الدفع الإلكتروني (card)
            'payment_type'       => ['required', Rule::in(['cash', 'card', 'credit'])],

            'subtotal'           => ['required', 'numeric', 'min:0'],
            'discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'tax_amount'         => ['nullable', 'numeric', 'min:0'],
            'grand_total'        => ['required', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string', 'max:1000'],
                'customer_name_text' => ['nullable', 'string', 'max:255'],

            // بيانات المصمم للتتبع الإداري

            // --- قواعد مصفوفة السطور (Items) المحدثة لنظام مبيعات عادي ---
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.item_id'          => ['required', 'exists:items,id'],
            'items.*.item_unit_id'     => ['required', 'exists:item_units,id'],
            'items.*.quantity'         => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0'],
            'items.*.subtotal'         => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'items.*.grand_total'      => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * التحقق المتقدم لمنع التلاعب بالكميات أثناء التحديث مع استثناء المستند الحالي من الاحتساب التراكمي للمرتجع
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);
            if (empty($items) || !is_array($items)) {
                return;
            }

            $parentId = $this->input('parent_id');
            $invoiceType = $this->input('invoice_type');
            $currentSale = $this->route('sale');

            // الرقابة الصارمة أثناء التعديل
            if ($invoiceType === 'return' && $parentId && $currentSale) {
                $parentSale = Sale::with('items')->find($parentId);

                if ($parentSale) {
                    // جلب المرتجعات السابقة مع استبعاد المرتجع الحالي تماماً من الحساب لتجنب تكرار الكميات
                    $previousReturns = Sale::where('parent_id', $parentId)
                        ->where('invoice_type', 'return')
                        ->where('id', '!=', $currentSale->id)
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
                        $originalTotals[$key]['quantity'] += (float)$pItem->quantity;
                    }

                    // تجميع إجمالي ما تم إرجاعه مسبقاً في المستندات الأخرى فقط
                    $returnedTotals = [];
                    foreach ($previousReturns as $prevReturn) {
                        foreach ($prevReturn->items as $rItem) {
                            $key = $rItem->item_id . '-' . $rItem->item_unit_id;
                            if (!isset($returnedTotals[$key])) {
                                $returnedTotals[$key] = [
                                    'quantity' => 0.00,
                                ];
                            }
                            $returnedTotals[$key]['quantity'] += (float)$rItem->quantity;
                        }
                    }

                    // مطابقة كميات التعديل الحالي ومقارنتها بالمتبقي الفعلي المتاح
                    foreach ($items as $index => $item) {
                        $itemId = $item['item_id'] ?? null;
                        $itemUnitId = $item['item_unit_id'] ?? null;
                        $key = $itemId . '-' . $itemUnitId;

                        // التحقق من أن الصنف تم شراؤه بالفعل في الفاتورة الأصلية
                        if (!isset($originalTotals[$key])) {
                            $validator->errors()->add("items.{$index}.item_id", "هذا الصنف غير موجود في سطور فاتورة المبيعات الأصلية المرجعية.");
                            continue;
                        }

                        $origQty = $originalTotals[$key]['quantity'];
                        $prevQty = $returnedTotals[$key]['quantity'] ?? 0.00;
                        $currentQty = (float)($item['quantity'] ?? 0.00);

                        $availableQty = $origQty - $prevQty;
                        if ($currentQty > $availableQty) {
                            $validator->errors()->add("items.{$index}.quantity", "الكمية المحدثة الحالية ({$currentQty}) تتجاوز الكمية المتبقية القابلة للإرجاع في الفاتورة الأصلية ({$availableQty}).");
                        }
                    }
                }
            }
        });
    }

    /**
     * تخصيص أسماء الحقول لرسائل الخطأ العربية الواضحة
     */
    public function attributes(): array
    {
        return [
            'invoice_type'            => 'نوع الفاتورة',
            'parent_id'               => 'فاتورة المبيعات الأصلية',
            'store_id'                => 'المخزن',
            'treasury_id'             => 'الخزنة المالية المستلمة',
            'bank_id'                 => 'الحساب البنكي المستلم',
            'customer_id'             => 'حساب العميل',
            'invoice_date'            => 'تاريخ الفاتورة',
            'payment_type'            => 'طريقة الدفع',
            'grand_total'             => 'الصافي النهائي للمبيعات',
            'items'                   => 'عناصر الفاتورة',
            'items.*.item_id'         => 'الصنف المباع',
            'items.*.item_unit_id'    => 'وحدة الصنف',
            'items.*.quantity'        => 'الكمية المباعة',
            'items.*.unit_price'      => 'سعر البيع للوحدة',
            'items.*.price_type' => 'نوع السعر المختار',
            'items.*.grand_total'     => 'إجمالي السطر',
            'sale_type'               => 'نوع حركة البيع (داخلي / خارجي)',
            'customer_name_text'      => 'اسم العميل الخارجي (النصي)',
        ];
    }
}
