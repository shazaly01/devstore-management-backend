<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class PreviewBulkRepriceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id'     => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'criterion_type'  => ['required', 'string', 'in:exchange_rate,percentage,target_margin'],
            'currency_id'     => ['required_if:criterion_type,exchange_rate', 'nullable', 'integer', 'exists:currencies,id'],
            'exchange_rate'   => ['required_if:criterion_type,exchange_rate', 'nullable', 'numeric', 'gt:0'],
            'percentage'      => ['required_if:criterion_type,percentage', 'nullable', 'numeric'],
            'target_margin'   => ['required_if:criterion_type,target_margin', 'nullable', 'numeric', 'min:0', 'max:100'],
            'profit_margin'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rounding_rule'   => ['required', 'string', 'in:none,nearest_50,nearest_100,nearest_500,psychological_90,psychological_900'],
        ];
    }

    /**
     * Custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id'    => 'التصنيف',
            'criterion_type' => 'معيار إعادة التسعير',
            'currency_id'    => 'العملة',
            'exchange_rate'  => 'سعر الصرف',
            'percentage'     => 'النسبة المئوية',
            'target_margin'  => 'هامش الربح المستهدف',
            'profit_margin'  => 'هامش الربح',
            'rounding_rule'  => 'قاعدة التقريب',
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'criterion_type.required'   => 'معيار إعادة التسعير مطلوب.',
            'criterion_type.in'         => 'معيار إعادة التسعير المحدد غير صالح.',
            'currency_id.required_if'   => 'يرجى اختيار العملة المرجعية عند التسعير بسعر الصرف.',
            'exchange_rate.required_if' => 'يرجى إدخال سعر الصرف المعتمد.',
            'exchange_rate.gt'          => 'يجب أن يكون سعر الصرف أكبر من صفر.',
            'percentage.required_if'    => 'يرجى إدخال نسبة تضخم / تعديل التكلفة.',
            'percentage.numeric'        => 'نسبة التعديل يجب أن تكون قيمة رقمية.',
            'target_margin.required_if' => 'يرجى تحديد هامش الربح المستهدف.',
            'target_margin.min'         => 'لا يمكن أن يقل هامش الربح المستهدف عن 0%.',
            'target_margin.max'         => 'لا يمكن أن يتجاوز هامش الربح المستهدف 100%.',
            'profit_margin.numeric'     => 'هامش الربح يجب أن يكون قيمة رقمية.',
            'profit_margin.min'         => 'لا يمكن أن يقل هامش الربح عن 0%.',
            'rounding_rule.required'    => 'قاعدة التقريب مطلوبة.',
            'rounding_rule.in'          => 'قاعدة التقريب المحددة غير صالحة.',
        ];
    }
}