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
            'category_id'     => ['nullable', 'integer', 'exists:categories,id'],
            'criterion_type'  => ['required', 'string', 'in:exchange_rate,percentage,target_margin'],
            'currency_id'     => ['required_if:criterion_type,exchange_rate', 'nullable', 'integer', 'exists:currencies,id'],
            'exchange_rate'   => ['required_if:criterion_type,exchange_rate', 'nullable', 'numeric', 'gt:0'],
            'percentage'      => ['required_if:criterion_type,percentage', 'nullable', 'numeric'],
            'target_margin'   => ['required_if:criterion_type,target_margin', 'nullable', 'numeric', 'min:0', 'max:100'],
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
            'rounding_rule'  => 'قاعدة التقريب',
        ];
    }
}