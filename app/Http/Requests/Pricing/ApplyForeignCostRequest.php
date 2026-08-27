<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class ApplyForeignCostRequest extends FormRequest
{
    /**
     * تحديد صلاحية المستخدم لتنفيذ هذا الطلب
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * قواعد التحقق من صحة بيانات تطبيق وتأصيل التكلفة الأجنبية
     */
    public function rules(): array
    {
        return [
            'currency_id'          => ['required', 'exists:currencies,id'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.item_unit_id' => ['required', 'exists:item_units,id'],
            'items.*.foreign_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * رسائل الخطأ المخصصة للتحقق
     */
    public function messages(): array
    {
        return [
            'currency_id.required'          => 'يرجى اختيار العملة المرجعية للشراء.',
            'currency_id.exists'            => 'العملة المختارة غير معرفة بالنظام.',
            'items.required'                => 'يجب تمرير عناصر الأصناف المراد تأصيل تكلفتها.',
            'items.array'                   => 'هيكل بيانات الأصناف يجب أن يكون مصفوفة.',
            'items.min'                     => 'يجب تحديد وحدة صنف واحدة على الأقل للتأصيل.',
            'items.*.item_unit_id.required' => 'معرف وحدة الصنف مطلوب لكل عنصر.',
            'items.*.item_unit_id.exists'   => 'وحدة الصنف المحددة غير موجودة بالنظام.',
            'items.*.foreign_cost.required' => 'التكلفة بالعملة الأجنبية مطلوبة لكل وحدة.',
            'items.*.foreign_cost.numeric'  => 'التكلفة بالعملة الأجنبية يجب أن تكون قيمة رقمية.',
            'items.*.foreign_cost.min'      => 'لا يمكن أن تكون التكلفة بالعملة الأجنبية أقل من صفر.',
        ];
    }
}