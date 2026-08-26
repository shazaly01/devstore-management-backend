<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class PreviewForeignCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
}