<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ImportItemsRequest extends FormRequest
{
    /**
     * التحقق من صلاحية المستخدم لتنفيذ الاستيراد
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * القواعد الصارمة للتحقق من مصفوفة الأصناف وأسعارها ووحداتها
     */
    public function rules(): array
    {
        return [
            'items'               => ['required', 'array', 'min:1'],
            'items.*.name'        => ['required', 'string', 'max:255'],
            'items.*.unit_name'   => ['nullable', 'string', 'max:100'],
            'items.*.cost'        => ['nullable', 'numeric', 'min:0'],
            'items.*.price'       => ['nullable', 'numeric', 'min:0'],
            'items.*.barcode'     => ['nullable', 'string', 'max:100'],
            'items.*.category_id' => ['nullable', 'exists:categories,id'],
        ];
    }

    /**
     * رسائل الخطأ التفصيلية المعربة لكل عنصر داخل المصفوفة
     */
    public function messages(): array
    {
        return [
            'items.required'            => 'يجب إرسال مصفوفة بيانات الأصناف المراد استيرادها.',
            'items.array'               => 'هيكل البيانات المرسل يجب أن يكون مصفوفة صالحة.',
            'items.min'                 => 'يجب أن يحتوي الملف على صنف واحد على الأقل للاستيراد.',
            'items.*.name.required'     => 'اسم الصنف مطلوب في كل صف مستورد.',
            'items.*.name.string'       => 'اسم الصنف يجب أن يكون نصاً صحيحاً.',
            'items.*.name.max'          => 'اسم الصنف لا يمكن أن يتجاوز 255 حرفاً.',
            'items.*.unit_name.string'  => 'اسم وحدة القياس يجب أن يكون نصاً صحيحاً.',
            'items.*.unit_name.max'     => 'اسم وحدة القياس لا يمكن أن يتجاوز 100 حرف.',
            'items.*.cost.numeric'      => 'سعر التكلفة يجب أن يكون قيمة رقمية.',
            'items.*.cost.min'          => 'سعر التكلفة لا يمكن أن يكون سالباً.',
            'items.*.price.numeric'     => 'سعر البيع يجب أن يكون قيمة رقمية.',
            'items.*.price.min'         => 'سعر البيع لا يمكن أن يكون سالباً.',
            'items.*.barcode.string'    => 'الباركود يجب أن يكون نصاً أو رقماً صحيحاً.',
            'items.*.barcode.max'       => 'الباركود لا يمكن أن يتجاوز 100 حرف.',
            'items.*.category_id.exists'=> 'التصنيف المحدد للصنف غير موجود بالنظام.',
        ];
    }

    /**
     * أسماء الحقول المعربة لظهور تنبيهات واضحة
     */
    public function attributes(): array
    {
        return [
            'items'               => 'قائمة الأصناف',
            'items.*.name'        => 'اسم الصنف',
            'items.*.unit_name'   => 'وحدة القياس',
            'items.*.cost'        => 'سعر التكلفة',
            'items.*.price'       => 'سعر البيع',
            'items.*.barcode'     => 'الباركود',
            'items.*.category_id' => 'التصنيف',
        ];
    }
}