<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // جلب كائن أو معرف الصنف الحالي بأمان من المسار (Route) لتخطي تكرار الباركود الخاص به
        $item = $this->route('item');
        $itemId = $item instanceof \App\Models\Item ? $item->id : $item;

        return [
            // البيانات الأساسية المحدثة للصنف
            'name'                                 => ['required', 'string', 'max:255'],
            'item_type'                            => ['required', 'in:product,service,raw_material'],
            'profit_margin'                        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'purchase_currency_id'                 => ['nullable', 'exists:currencies,id'],
            'pricing_policy'                       => ['nullable', 'in:manual,auto_indexed,phased'],
            'min_margin_percentage'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rounding_rule'                        => ['nullable', 'in:none,nearest_50,nearest_100,nearest_500,psychological_90,psychological_900'],
            'category_id'                          => ['nullable', 'exists:categories,id'],
            'category_path'                        => ['nullable', 'string'],
            'base_unit_id'                         => ['required', 'exists:units,id'],
            'is_active'                            => ['required', 'boolean'],
            'is_composite'                         => ['required', 'boolean'],
            'expiry_date'                          => ['nullable', 'date'],

            // مصفوفة المكونات التجميعية للتعديل (مطلوبة فقط في حال كان الصنف تجميعياً)
            'components'                           => ['required_if:is_composite,true', 'nullable', 'array'],
            'components.*.child_item_id'           => ['required_if:is_composite,true', 'exists:items,id'],
            'components.*.quantity'                => ['required_if:is_composite,true', 'numeric', 'min:0.001'],

            // مصفوفة الوحدات المتعددة (الديناميكية) المرسلة من الواجهة الأمامية
            'units'                                => ['required', 'array', 'min:1'],
            'units.*.unit_id'                      => ['required', 'exists:units,id'],
            'units.*.conversion_factor'            => ['required', 'numeric', 'min:0.0001'],
            'units.*.cost'                         => ['required', 'numeric', 'min:0'],
            'units.*.foreign_cost'                 => ['nullable', 'numeric', 'min:0'],
            'units.*.price'                        => ['required', 'numeric', 'min:0'],

            // مصفوفة الباركودات التابعة لكل وحدة (مع استثناء الصنف الحالي عبر itemId وعمود item_id الفرعي)
            'units.*.barcodes'                     => ['nullable', 'array'],
            'units.*.barcodes.*'                   => [
                'required',
                'string',
                'unique:item_barcodes,barcode,' . $itemId . ',item_id'
            ],

            // مصفوفة فئات الأسعار والخصومات التابعة لكل وحدة (Pricing Matrix)
            'units.*.prices'                       => ['nullable', 'array'],
            'units.*.prices.*.price_list_id'       => ['required', 'exists:price_lists,id'],
            'units.*.prices.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'units.*.prices.*.price'               => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * رسائل الخطأ المخصصة بالعربية المتوافقة مع المصفوفات الشجرية
     */
    public function messages(): array
    {
        return [
            'name.required'                            => 'اسم الصنف أو الخدمة مطلوب لتحديث البيانات ولا يمكن تركه فارغاً.',
            'item_type.required'                       => 'طبيعة الصنف مطلوبة (منتج، خدمة، أو مادة خام).',
            'item_type.in'                             => 'طبيعة الصنف الممررة غير صحيحة.',
            'profit_margin.numeric'                    => 'يجب أن تكون نسبة الربح قيمة رقمية.',
            'profit_margin.min'                        => 'لا يمكن أن تكون نسبة الربح أقل من 0%.',
            'profit_margin.max'                        => 'لا يمكن أن تتجاوز نسبة الربح 100%.',
            'purchase_currency_id.exists'              => 'العملة المرجعية المختارة غير موجودة بالنظام.',
            'pricing_policy.in'                        => 'سياسة التسعير المحددة غير صالحة.',
            'min_margin_percentage.numeric'            => 'يجب أن يكون الحد الأدنى للهامش قيمة رقمية.',
            'min_margin_percentage.min'                => 'لا يمكن أن يقل الحد الأدنى للهامش عن 0%.',
            'min_margin_percentage.max'                => 'لا يمكن أن يتجاوز الحد الأدنى للهامش 100%.',
            'rounding_rule.in'                         => 'قاعدة التقريب المحددة غير صالحة.',
            'category_id.exists'                       => 'التصنيف المختار غير موجود في النظام.',
            'base_unit_id.required'                    => 'يجب تحديد الوحدة القياسية الصغرى للصنف.',
            'base_unit_id.exists'                      => 'الوحدة الأساسية المختارة غير موجودة بدليل الوحدات.',
            'is_active.required'                       => 'حالة تفعيل أو إيقاف الصنف مطلوبة لتعديل الملف الإداري.',
            'is_composite.required'                    => 'يجب تحديد ما إذا كان الصنف تجميعياً أم لا تزامناً مع التحديث.',
            'expiry_date.date'                         => 'تاريخ الصلاحية يجب أن يكون تاريخاً صحيحاً.',

            // المكونات التجميعية
            'components.required_if'                   => 'يجب إدراج مكون واحد على الأقل للصنف التجميعي المحدث.',
            'components.*.child_item_id.required_if'   => 'تحديد صنف المكون مطلوب.',
            'components.*.child_item_id.exists'        => 'الصنف المختار كمكون غير موجود بالنظام.',
            'components.*.quantity.required_if'        => 'كمية المكون مطلوبة للسطر.',
            'components.*.quantity.min'                => 'يجب أن تكون كمية المكون المحدث أكبر من صفر.',

            // الوحدات المتعددة
            'units.required'                           => 'يجب تأمين وحدة قياس واحدة على الأقل للصنف ضمن المصفوفة.',
            'units.*.unit_id.required'                 => 'تحديد الوحدة مطلوب للسطر.',
            'units.*.unit_id.exists'                   => 'وحدة القياس المدرجة غير موجودة بدليل الوحدات.',
            'units.*.conversion_factor.required'       => 'معامل التحويل مطلوب لكل وحدة مضافة.',
            'units.*.conversion_factor.numeric'        => 'يجب أن يكون معامل التحويل رقماً صحيحاً أو عشرياً.',
            'units.*.conversion_factor.min'            => 'لا يمكن أن يكون معامل التحويل صفراً أو أقل.',
            'units.*.cost.required'                    => 'سعر التكلفة مطلوب للوحدة المدرجة.',
            'units.*.cost.numeric'                     => 'يجب أن يكون سعر التكلفة قيمة رقمية.',
            'units.*.foreign_cost.numeric'             => 'يجب أن تكون التكلفة بالعملة الأجنبية قيمة رقمية.',
            'units.*.foreign_cost.min'                 => 'لا يمكن أن تكون التكلفة بالعملة الأجنبية أقل من صفر.',
            'units.*.price.required'                   => 'سعر البيع الافتراضي مطلوب للوحدة المدرجة.',
            'units.*.price.numeric'                    => 'يجب أن يكون سعر البيع قيمة رقمية.',

            // الباركودات
            'units.*.barcodes.*.required'              => 'قيمة الباركود لا يمكن أن تكون فارغة.',
            'units.*.barcodes.*.unique'                => 'هذا الباركود مستخدم مسبقاً مع صنف مالي آخر بالنظام.',

            // فئات الأسعار البيعية
            'units.*.prices.*.price_list_id.required'  => 'فئة السعر (مثل: جملة، جمهور) مطلوبة.',
            'units.*.prices.*.price_list_id.exists'    => 'فئة السعر المحددة غير موجودة بالنظام.',
            'units.*.prices.*.discount_percentage.min' => 'نسبة الخصم لفئة السعر لا يمكن أن تقل عن 0%.',
            'units.*.prices.*.discount_percentage.max' => 'نسبة الخصم لفئة السعر لا يمكن أن تتجاوز 100%.',
            'units.*.prices.*.price.required'          => 'السعر الفعلي النهائي لهذه الفئة مطلوب.',
        ];
    }
}