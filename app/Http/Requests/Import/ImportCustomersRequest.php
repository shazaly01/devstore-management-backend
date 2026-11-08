<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ImportCustomersRequest extends FormRequest
{
    /**
     * التحقق من صلاحية المستخدم لتنفيذ الاستيراد
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * القواعد الصارمة للتحقق من مصفوفة العملاء الممررة من الواجهة
     */
    public function rules(): array
    {
        return [
            'customers'                     => ['required', 'array', 'min:1'],
            'customers.*.name'              => ['required', 'string', 'max:255'],
            'customers.*.phone'             => ['nullable', 'string', 'max:50'],
            'customers.*.email'             => ['nullable', 'email', 'max:255'],
            'customers.*.credit_limit'      => ['nullable', 'numeric', 'min:0'],
            'customers.*.opening_balance'   => ['nullable', 'numeric', 'min:0'],
            'customers.*.price_list_id'     => ['nullable', 'exists:price_lists,id'],
        ];
    }

    /**
     * رسائل الخطأ التفصيلية المعربة لكل عنصر داخل المصفوفة
     */
    public function messages(): array
    {
        return [
            'customers.required'                   => 'يجب إرسال مصفوفة بيانات العملاء المراد استيرادها.',
            'customers.array'                      => 'هيكل البيانات المرسل يجب أن يكون مصفوفة صالحة.',
            'customers.min'                        => 'يجب أن يحتوي الملف على عميل واحد على الأقل للاستيراد.',
            'customers.*.name.required'            => 'اسم العميل مطلوب في كل صف مستورد.',
            'customers.*.name.string'              => 'اسم العميل يجب أن يكون نصاً صحيحاً.',
            'customers.*.name.max'                 => 'اسم العميل لا يمكن أن يتجاوز 255 حرفاً.',
            'customers.*.phone.max'                => 'رقم الهاتف لا يمكن أن يتجاوز 50 حرفاً.',
            'customers.*.email.email'              => 'صيغة البريد الإلكتروني غير صالحة.',
            'customers.*.email.max'                => 'البريد الإلكتروني لا يمكن أن يتجاوز 255 حرفاً.',
            'customers.*.credit_limit.numeric'     => 'الحد الائتماني يجب أن يكون قيمة رقمية.',
            'customers.*.credit_limit.min'         => 'الحد الائتماني لا يمكن أن يكون سالباً.',
            'customers.*.opening_balance.numeric'  => 'الرصيد الافتتاحي يجب أن يكون قيمة رقمية.',
            'customers.*.opening_balance.min'      => 'الرصيد الافتتاحي لا يمكن أن يكون بالسالب.',
            'customers.*.price_list_id.exists'     => 'فئة السعر المحددة للعميل غير موجودة بالنظام.',
        ];
    }

    /**
     * أسماء الحقول المعربة لظهور تنبيهات واضحة
     */
    public function attributes(): array
    {
        return [
            'customers'                   => 'قائمة العملاء',
            'customers.*.name'            => 'اسم العميل',
            'customers.*.phone'           => 'رقم الهاتف',
            'customers.*.email'           => 'البريد الإلكتروني',
            'customers.*.credit_limit'    => 'الحد الائتماني',
            'customers.*.opening_balance' => 'الرصيد الافتتاحي',
            'customers.*.price_list_id'   => 'فئة قائمة الأسعار',
        ];
    }
}