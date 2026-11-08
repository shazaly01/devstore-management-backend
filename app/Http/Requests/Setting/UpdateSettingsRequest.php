<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * تحديد ما إذا كان المستخدم مخولاً لتنفيذ هذا الطلب.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * تهيئة وتنظيم البيانات قبل التحقق منها لدعم المرونة
     * (يدعم إرسال مصفوفة مفاتيح مباشرة أو مغلفة داخل كائن settings).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('settings') && ! empty($this->all())) {
            $this->merge([
                'settings' => $this->all(),
            ]);
        }
    }

    /**
     * قواعد التحقق من صحة مدخلات الإعدادات.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 1. إذا تم إرسال الإعدادات كمصفوفة ترابطية سريعة: { "settings": { "company_name": "...", "pos_allow_negative_sale": true } }
        if ($this->has('settings') && is_array($this->input('settings')) && ! array_is_list($this->input('settings'))) {
            return [
                'settings' => ['required', 'array'],
                'settings.*' => ['nullable'],
            ];
        }

        // 2. إذا تم إرسال الإعدادات كمصفوفة عناصر: { "settings": [ {"key": "company_name", "value": "..."}, ... ] }
        return [
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'exists:settings,key'],
            'settings.*.value' => ['nullable'],
        ];
    }

    /**
     * تخصيص رسائل الخطأ باللغة العربية.
     */
    public function messages(): array
    {
        return [
            'settings.required' => 'حقل الإعدادات مطلوب.',
            'settings.array' => 'يجب أن تكون بنية الإعدادات المرسلة مصفوفة صالحة.',
            'settings.*.key.required' => 'مفتاح الإعداد مطلوب.',
            'settings.*.key.exists' => 'أحد مفاتيح الإعدادات المرسلة غير موجود أو غير معرّف في النظام.',
        ];
    }
}