<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class ApplyForeignCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency_id'                => ['required', 'exists:currencies,id'],
            'items'                      => ['required', 'array', 'min:1'],
            'items.*.item_unit_id'       => ['required', 'exists:item_units,id'],
            'items.*.foreign_cost'       => ['required', 'numeric', 'min:0'],
        ];
    }
}