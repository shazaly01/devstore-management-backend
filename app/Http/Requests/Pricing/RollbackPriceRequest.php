<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class RollbackPriceRequest extends FormRequest
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
            'main_id'    => ['required_without:batch_code', 'nullable', 'integer', 'exists:item_price_history_mains,id'],
            'batch_code' => ['required_without:main_id', 'nullable', 'string', 'exists:item_price_history_mains,batch_code'],
            'notes'      => ['nullable', 'string', 'max:1000'],
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
            'main_id'    => 'معرف دفعة التسعير',
            'batch_code' => 'كود دفعة التسعير',
            'notes'      => 'سبب التراجع / الملاحظات',
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'main_id.required_without'    => 'يرجى تحديد معرف الدفعة المراد التراجع عنها.',
            'main_id.exists'              => 'دفعة التسعير المحددة غير موجودة.',
            'batch_code.required_without' => 'يرجى إدخال كود الدفعة المراد التراجع عنها.',
            'batch_code.exists'           => 'كود دفعة التسعير غير موجود بالنظام.',
        ];
    }
}