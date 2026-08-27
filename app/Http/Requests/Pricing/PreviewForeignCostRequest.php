<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class PreviewForeignCostRequest extends FormRequest
{
    /**
     * تحديد صلاحية المستخدم لتنفيذ هذا الطلب
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * قواعد التحقق من صحة معايير وفلاتر معاينة التأصيل
     */
    public function rules(): array
    {
        return [
            'method'        => ['required', 'in:from_cost_rate,reverse_from_price_margin,ratio_from_price'],
            'exchange_rate' => ['required', 'numeric', 'min:0.0001'],
            'currency_id'   => ['required', 'exists:currencies,id'],
            'category_id'   => ['nullable', 'exists:categories,id'],
            'profit_margin' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'margin_ratio'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'only_missing'  => ['nullable', 'boolean'],
        ];
    }

    /**
     * رسائل الخطأ المخصصة لمعاينة التأصيل
     */
    public function messages(): array
    {
        return [
            'method.required'        => 'طريقة احتساب التكلفة الأجنبية مطلوبة.',
            'method.in'              => 'طريقة الاحتساب المحددة غير صالحة.',
            'exchange_rate.required' => 'سعر الصرف مطلوب لإجراء المعاينة الحسابية.',
            'exchange_rate.numeric'  => 'يجب أن يكون سعر الصرف قيمة رقمية.',
            'exchange_rate.min'      => 'لا يمكن أن يكون سعر الصرف صفراً أو أقل.',
            'currency_id.required'   => 'العملة المرجعية مطلوبة.',
            'currency_id.exists'     => 'العملة المحددة غير موجودة بالنظام.',
            'category_id.exists'     => 'التصنيف المختار غير موجود بالنظام.',
            'profit_margin.numeric'  => 'هامش الربح يجب أن يكون قيمة رقمية.',
            'profit_margin.min'      => 'لا يمكن أن يقل هامش الربح عن 0%.',
            'profit_margin.max'      => 'لا يمكن أن يتجاوز هامش الربح 100%.',
            'margin_ratio.numeric'   => 'نسبة الخصم من السعر يجب أن تكون قيمة رقمية.',
            'margin_ratio.min'       => 'لا يمكن أن تقل النسبة عن 0%.',
            'margin_ratio.max'       => 'لا يمكن أن تتجاوز النسبة 100%.',
            'only_missing.boolean'   => 'خيار الفلترة يجب أن يكون قيمة منطقية.',
        ];
    }
}