<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
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
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'rate'        => ['required', 'numeric', 'gt:0'],
            'rate_date'   => ['required', 'date'],
            'notes'       => ['nullable', 'string', 'max:1000'],
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
            'currency_id' => 'العملة',
            'rate'        => 'سعر الصرف',
            'rate_date'   => 'تاريخ الصرف',
            'notes'       => 'الملاحظات',
        ];
    }
}