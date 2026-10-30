<?php

namespace App\Http\Requests\ItemDisassembly;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemDisassemblyRequest extends FormRequest
{
    /**
     * تحديد ما إذا كان المستخدم مصرحاً له بإجراء هذا الطلب.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * قواعد التحقق المطبقة على طلب تعديل إذن التفكيك.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'disassembly_date'            => ['required', 'date'],
            'store_id'                    => ['required', 'integer', 'exists:stores,id'],
            'source_item_id'              => ['required', 'integer', 'exists:items,id'],
            'source_item_unit_id'         => ['required', 'integer', 'exists:item_units,id'],
            'source_quantity'             => ['required', 'numeric', 'gt:0'],
            'source_unit_cost'            => ['nullable', 'numeric', 'min:0'],
            'source_total_cost'           => ['nullable', 'numeric', 'min:0'],
            'waste_quantity'              => ['nullable', 'numeric', 'min:0'],
            'notes'                       => ['nullable', 'string', 'max:1000'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.item_id'             => ['required', 'integer', 'exists:items,id', 'different:source_item_id'],
            'items.*.item_unit_id'        => ['required', 'integer', 'exists:item_units,id'],
            'items.*.quantity'            => ['required', 'numeric', 'gt:0'],
            'items.*.expected_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost'           => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'               => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * الرسائل المخصصة لأخطاء التحقق.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'disassembly_date.required'     => 'تاريخ التفكيك مطلوب.',
            'disassembly_date.date'         => 'صيغة تاريخ التفكيك غير صالحة.',
            'store_id.required'             => 'يجب تحديد المستودع الذي ستتم فيه العملية.',
            'store_id.exists'               => 'المستودع المحدد غير موجود في النظام.',
            'source_item_id.required'       => 'يجب تحديد الصنف الخام المراد تفكيكه.',
            'source_item_id.exists'         => 'الصنف الخام المحدد غير موجود في دليل الأصناف.',
            'source_item_unit_id.required'  => 'يجب تحديد وحدة الصنف الخام.',
            'source_item_unit_id.exists'    => 'وحدة الصنف الخام المحددة غير صحيحة.',
            'source_quantity.required'      => 'يجب إدخال كمية الصنف الخام المستهلك.',
            'source_quantity.gt'            => 'يجب أن تكون كمية الصنف الخام أكبر من الصفر.',
            'source_unit_cost.numeric'      => 'تكلفة وحدة الصنف الخام يجب أن تكون قيمة عددية.',
            'source_unit_cost.min'          => 'تكلفة وحدة الصنف الخام لا يمكن أن تكون سالبة.',
            'source_total_cost.numeric'     => 'إجمالي تكلفة الصنف الخام يجب أن تكون قيمة عددية.',
            'source_total_cost.min'         => 'إجمالي تكلفة الصنف الخام لا يمكن أن تكون سالبة.',
            'waste_quantity.min'            => 'كمية الهالك لا يمكن أن تكون قيمة سالبة.',
            'items.required'                => 'يجب إضافة صنف ناتج واحد على الأقل من عملية التفكيك.',
            'items.min'                     => 'يجب إضافة صنف ناتج واحد على الأقل من عملية التفكيك.',
            'items.*.item_id.required'      => 'يجب تحديد الصنف الناتج لكل سطر.',
            'items.*.item_id.exists'        => 'أحد الأصناف الناتجة غير موجود في دليل الأصناف.',
            'items.*.item_id.different'     => 'لا يمكن أن يكون الصنف الناتج مطابقاً للصنف الخام المصروف.',
            'items.*.item_unit_id.required' => 'يجب تحديد وحدة القياس لكل صنف ناتج.',
            'items.*.item_unit_id.exists'   => 'وحدة القياس المحددة لأحد الأصناف الناتجة غير صحيحة.',
            'items.*.quantity.required'     => 'يجب تحديد وزن أو كمية كل صنف ناتج.',
            'items.*.quantity.gt'           => 'يجب أن تكون كمية الصنف الناتج أكبر من الصفر.',
        ];
    }
}