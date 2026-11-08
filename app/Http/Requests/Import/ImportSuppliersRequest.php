<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ImportSuppliersRequest extends FormRequest
{
    /**
     * التحقق من صلاحية المستخدم لتنفيذ الاستيراد
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * القواعد الصارمة للتحقق من مصفوفة الموردين الممررة من الواجهة
     */
    public function rules(): array
    {
        return [
            'suppliers'                   => ['required', 'array', 'min:1'],
            'suppliers.*.name'            => ['required', 'string', 'max:255'],
            'suppliers.*.phone'           => ['nullable', 'string', 'max:50'],
            'suppliers.*.tax_number'      => ['nullable', 'string', 'max:50'],
            'suppliers.*.opening_balance' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * رسائل الخطأ التفصيلية المعربة لكل عنصر داخل المصفوفة
     */
    public function messages(): array
    {
        return [
            'suppliers.required'                   => 'يجب إرسال مصفوفة بيانات الموردين المراد استيرادها.',
            'suppliers.array'                      => 'هيكل البيانات المرسل يجب أن يكون مصفوفة صالحة.',
            'suppliers.min'                        => 'يجب أن يحتوي الملف على مورد واحد على الأقل للاستيراد.',
            'suppliers.*.name.required'            => 'اسم المورد مطلوب في كل صف مستورد.',
            'suppliers.*.name.string'              => 'اسم المورد يجب أن يكون نصاً صحيحاً.',
            'suppliers.*.name.max'                 => 'اسم المورد لا يمكن أن يتجاوز 255 حرفاً.',
            'suppliers.*.phone.max'                => 'رقم الهاتف لا يمكن أن يتجاوز 50 حرفاً.',
            'suppliers.*.tax_number.max'           => 'الرقم الضريبي لا يمكن أن يتجاوز 50 حرفاً.',
            'suppliers.*.opening_balance.numeric'  => 'الرصيد الافتتاحي يجب أن يكون قيمة رقمية.',
            'suppliers.*.opening_balance.min'      => 'الرصيد الافتتاحي للمورد لا يمكن أن يكون بالسالب.',
        ];
    }

    /**
     * أسماء الحقول المعربة لظهور تنبيهات واضحة
     */
    public function attributes(): array
    {
        return [
            'suppliers'                   => 'قائمة الموردين',
            'suppliers.*.name'            => 'اسم المورد',
            'suppliers.*.phone'           => 'رقم الهاتف',
            'suppliers.*.tax_number'      => 'الرقم الضريبي',
            'suppliers.*.opening_balance' => 'الرصيد الافتتاحي',
        ];
    }
}