<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * تشغيل بذور الإعدادات الافتراضية للنظام.
     */
    public function run(): void
    {
        $settings = [
            // -------------------------------------------------------------
            // 1. الإعدادات العامة وبيانات المنشأة (General Information)
            // -------------------------------------------------------------
            [
                'key' => 'company_name',
                'value' => 'مؤسسة باندا التجارية',
                'group' => 'general',
                'type' => 'string',
                'label' => 'اسم المنشأة / الشركة',
                'description' => 'الاسم التجاري الذي يظهر في الفواتير والترويسة والتقارير.',
            ],
            [
                'key' => 'company_phones',
                'value' => ['0123456789'],
                'group' => 'general',
                'type' => 'json',
                'label' => 'أرقام هواتف التواصل',
                'description' => 'قائمة أرقام الهواتف التابعة للمنشأة والمطبوعة على الفاتورة.',
            ],
            [
                'key' => 'company_address',
                'value' => 'السودان - الخرطوم',
                'group' => 'general',
                'type' => 'string',
                'label' => 'عنوان المنشأة',
                'description' => 'العنوان الفعلي للمقر الرئيسي أو المعرض.',
            ],
            [
                'key' => 'company_tax_number',
                'value' => null,
                'group' => 'general',
                'type' => 'string',
                'label' => 'الرقم الضريبي / السجل التجاري',
                'description' => 'الرقم التعريفي الضريبي أو رقم السجل للمنشأة.',
            ],

            // -------------------------------------------------------------
            // 2. إعدادات نقطة البيع والكاشير (POS & Cashier Controls)
            // -------------------------------------------------------------
            [
                'key' => 'pos_allow_negative_sale',
                'value' => false,
                'group' => 'pos',
                'type' => 'boolean',
                'label' => 'السماح بالبيع بالسالب',
                'description' => 'إتاحة إتمام فاتورة البيع حتى وإن كانت الكمية غير متوفرة في المخزن.',
            ],
            [
                'key' => 'pos_warn_low_stock',
                'value' => true,
                'group' => 'pos',
                'type' => 'boolean',
                'label' => 'تنبيه وصول حد الطلب',
                'description' => 'إظهار تنبيه للكاشير عند وصول الصنف إلى حد إعادة الطلب.',
            ],
            [
                'key' => 'pos_allow_custom_discount',
                'value' => true,
                'group' => 'pos',
                'type' => 'boolean',
                'label' => 'السماح بتعديل الخصم يدوياً',
                'description' => 'تمكين الكاشير من إدخال نسبة أو قيمة خصم أثناء البيع.',
            ],

            // -------------------------------------------------------------
            // 3. إعدادات طباعة وتصميم الفاتورة (Printing & Invoice Layout)
            // -------------------------------------------------------------
            [
                'key' => 'print_invoice_footer_text',
                'value' => 'شكراً لتعاملكم معنا، البضاعة المباعة ترد وتستبدل خلال 3 أيام بموجب الفاتورة.',
                'group' => 'printing',
                'type' => 'string',
                'label' => 'تذييل الفاتورة المطبوعة',
                'description' => 'ملاحظات أو سياسة الاسترجاع المطبوعة أسفل الفاتورة.',
            ],
            [
                'key' => 'print_show_tax_number',
                'value' => false,
                'group' => 'printing',
                'type' => 'boolean',
                'label' => 'إظهار الرقم الضريبي في الفاتورة',
                'description' => 'طباعة الرقم الضريبي في ترويسة فاتورة الكاشير.',
            ],
            [
                'key' => 'print_show_phone',
                'value' => true,
                'group' => 'printing',
                'type' => 'boolean',
                'label' => 'إظهار أرقام الهواتف في الفاتورة',
                'description' => 'طباعة أرقام هواتف المنشأة في الفاتورة.',
            ],
            [
                'key' => 'print_receipt_width',
                'value' => 80,
                'group' => 'printing',
                'type' => 'integer',
                'label' => 'عرض ورق الطباعة الحرارية (ملم)',
                'description' => 'مقاس ورق طابعة الإيصالات الحرارية (غالباً 80 أو 57 ملم).',
            ],
        ];

        foreach ($settings as $settingData) {
            Setting::firstOrCreate(
                ['key' => $settingData['key']],
                $settingData
            );
        }
    }
}