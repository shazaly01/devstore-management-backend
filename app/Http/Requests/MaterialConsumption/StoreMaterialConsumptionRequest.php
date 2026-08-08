<?php

namespace App\Http\Requests\MaterialConsumption;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialConsumptionRequest extends FormRequest
{
    /**
     * التحقق من صلاحية المستخدم (الفني) لإدخال حركة الاستهلاك بناءً على قواعد Spatie
     */
    public function authorize(): bool
    {
        // [التزام بقواعد النظام]: التحقق المباشر من امتلاك الفني للصلاحية القديمة المعتمدة عبر حزمة Spatie
        return $this->user() && $this->user()->hasPermissionTo('sale.swap_raw_materials', 'api');
    }

    /**
     * قواعد التحقق الصارمة لحركة الاستهلاك الفردية فائقة البساطة
     */
    public function rules(): array
    {
        return [
            'store_id'     => ['required', 'exists:stores,id'],
            'item_id'      => ['required', 'exists:items,id'],
            'item_unit_id' => ['required', 'exists:item_units,id'],
            'quantity'     => ['required', 'numeric', 'gt:0'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * تخصيص أسماء الحقول لرسائل أخطاء عربية واضحة ومباشرة للفني في الورشة
     */
    public function attributes(): array
    {
        return [
            'store_id'     => 'المخزن / المستودع',
            'item_id'     => 'المادة الخام المستهدفة',
            'item_unit_id' => 'وحدة السحب المختار',
            'quantity'     => 'الكمية المستهلكة',
            'notes'        => 'البيان الحر / الملاحظة',
        ];
    }
}
