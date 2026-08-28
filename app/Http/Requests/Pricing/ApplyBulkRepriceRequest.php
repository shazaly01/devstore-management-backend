<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class ApplyBulkRepriceRequest extends FormRequest
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
            'category_id'           => ['nullable', 'integer', 'exists:categories,id'],
            'currency_id'           => ['nullable', 'integer', 'exists:currencies,id'],
            'exchange_rate'         => ['nullable', 'numeric', 'gt:0'],
            'change_type'           => ['required', 'string', 'in:bulk_reprice,manual,auto_indexed,exchange_rate,percentage,target_margin'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.item_id'       => ['required', 'integer', 'exists:items,id'],
            'items.*.item_unit_id'  => ['required', 'integer', 'exists:item_units,id'],
            'items.*.old_price'     => ['required', 'numeric', 'gte:0'],
            'items.*.new_price'     => ['required', 'numeric', 'gte:0'],
            'items.*.old_cost'      => ['nullable', 'numeric', 'gte:0'],
            'items.*.new_cost'      => ['nullable', 'numeric', 'gte:0'],
            'items.*.profit_margin' => ['nullable', 'numeric', 'min:0'],
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
            'category_id'           => 'التصنيف',
            'currency_id'           => 'العملة',
            'exchange_rate'         => 'سعر الصرف',
            'change_type'           => 'نوع التعديل',
            'notes'                 => 'الملاحظات',
            'items'                 => 'قائمة الأصناف',
            'items.*.item_id'       => 'الصنف',
            'items.*.item_unit_id'  => 'وحدة الصنف',
            'items.*.old_price'     => 'السعر القديم',
            'items.*.new_price'     => 'السعر الجديد',
            'items.*.old_cost'      => 'التكلفة القديمة',
            'items.*.new_cost'      => 'التكلفة الجديدة',
            'items.*.profit_margin' => 'هامش الربح',
        ];
    }
}