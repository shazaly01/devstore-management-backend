<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ $sale->invoice_type === 'sale' ? 'فاتورة مبيعات' : 'مردودات مبيعات' }} - {{ $sale->invoice_number }}</title>
    <style>
        body {
            font-family: 'dejavusans', sans-serif;
            direction: rtl;
            text-align: right;
            font-size: 11px;
            color: #1e293b;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
        }

        /* الشريط الملون العلوي */
        .top-stripe-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .stripe-orange {
            background-color: #ea580c;
            height: 6px;
            width: 66.66%;
        }
        .stripe-amber {
            background-color: #fbbf24;
            height: 6px;
            width: 8.33%;
        }
        .stripe-slate {
            background-color: #334155;
            height: 6px;
            width: 25.01%;
        }

        /* رأس الفاتورة والشعار */
        .header-table {
            width: 100%;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            text-align: center;
        }

        .badge-type {
            background-color: #fff7ed;
            color: #ea580c;
            border-right: 3px solid #ea580c;
            padding: 3px 8px;
            font-weight: bold;
            font-size: 11px;
            display: inline-block;
            margin-bottom: 4px;
        }

        .badge-number {
            background-color: #f1f5f9;
            color: #0f172a;
            font-family: monospace;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
        }

        .badge-sale-type {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid;
        }
        .sale-type-indoor {
            background-color: #ecfdf5;
            color: #047857;
            border-color: #6ee7b7;
        }
        .sale-type-outdoor {
            background-color: #fffbeb;
            color: #b45309;
            border-color: #fcd34d;
        }

        /* بطاقة بيانات العميل والملاحظات */
        .client-info-table {
            width: 100%;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
        }

        .section-title-orange {
            color: #ea580c;
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 4px;
        }

        .section-title-slate {
            color: #94a3b8;
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 4px;
        }

        /* جدول الأصناف */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        .items-table th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 10.5px;
            font-weight: bold;
            padding: 7px 6px;
            border-bottom: 1px solid #cbd5e1;
            text-align: center;
        }

        .items-table td {
            padding: 7px 6px;
            font-size: 10px;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }

        .text-right { text-align: right !important; }
        .text-left { text-align: left !important; }
        .text-center { text-align: center !important; }

        /* شريط الإجماليات */
        .totals-container-table {
            width: 100%;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 24px;
        }

        .net-total-box {
            background-color: #ffedd5;
            border: 1px solid #fdba74;
            border-radius: 6px;
            padding: 5px 12px;
            color: #ea580c;
            font-weight: bold;
            display: inline-block;
        }

        /* التوقيعات */
        .signatures-table {
            width: 100%;
            margin-top: 15px;
            text-align: center;
        }

        .signature-line {
            border-bottom: 1px dashed #cbd5e1;
            width: 110px;
            margin: 25px auto 0 auto;
        }
    </style>
</head>
<body>

    @php
        // مسار الشعار المحلي وتحويله لـ Base64
        $logoPath = public_path('storage/ReportLogo.png');
        if (!file_exists($logoPath)) {
            $logoPath = storage_path('app/public/ReportLogo.png');
        }
        $logoBase64 = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : null;

        $paymentTypeLabels = [
            'cash'   => 'نقدي',
            'card'   => 'شبكة / دفع إلكتروني',
            'credit' => 'آجل / ذمم',
        ];

        // دالة تنسيق الأرقام الذكية
        $formatSmart = function ($val) {
            if ($val === null || $val === '') return '0';
            $num = (float) $val;
            return (fmod($num, 1) == 0.0) ? number_format($num, 0) : number_format($num, 2);
        };
    @endphp

    <!-- الشريط العلوي الملون -->
    <table class="top-stripe-table">
        <tr>
            <td class="stripe-orange"></td>
            <td class="stripe-amber"></td>
            <td class="stripe-slate"></td>
        </tr>
    </table>

    <!-- رأس الفاتورة والشعار -->
    <table class="header-table">
        <tr>
            <!-- جهة الشعار -->
            <td style="width: 25%; vertical-align: middle;">
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" style="max-height: 70px; max-width: 160px; object-fit: contain;" alt="شعار الشركة">
                @else
                    <div style="font-size: 14px; font-weight: bold; color: #ea580c;">{{ $sale->store->name ?? '' }}</div>
                @endif
            </td>

            <!-- اسم المنشأة / التطبيق في المنتصف عبر config('app.name') -->
            <td style="width: 35%; vertical-align: middle; text-align: center;">
                <div class="company-name">{{ config('app.name') }}</div>
            </td>

            <!-- تفاصيل الفاتورة -->
            <td style="width: 40%; vertical-align: middle; text-align: left; line-height: 1.6;">
                <div>
                    <span class="badge-type">
                        {{ $sale->invoice_type === 'sale' ? 'فاتورة مبيعات' : 'مردودات مبيعات' }}
                    </span>
                </div>
                <div style="font-size: 10px; margin-top: 2px;">
                    <span style="color: #64748b; font-weight: bold;">رقم الفاتورة:</span>
                    <span class="badge-number">{{ $sale->invoice_number }}</span>
                </div>
                <div style="font-size: 10px; margin-top: 2px;">
                    <span style="color: #64748b; font-weight: bold;">التاريخ:</span>
                    <span style="font-family: monospace; font-weight: bold; color: #1e293b;">
                        {{ $sale->invoice_date ? $sale->invoice_date->format('Y-m-d') : $sale->created_at->format('Y-m-d') }}
                    </span>
                </div>
                <div style="font-size: 10px; margin-top: 2px;">
                    <span style="color: #64748b; font-weight: bold;">نوع البيع:</span>
                    <span class="badge-sale-type {{ $sale->sale_type === 'indoor' ? 'sale-type-indoor' : 'sale-type-outdoor' }}">
                        {{ $sale->sale_type === 'indoor' ? 'داخلي' : 'خارجي' }}
                    </span>
                     
                    <span style="color: #64748b; font-weight: bold;">طريقة الدفع:</span>
                    <span style="font-weight: bold; color: #1e293b;">
                        {{ $paymentTypeLabels[$sale->payment_type] ?? $sale->payment_type }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- بطاقة تفاصيل العميل والملاحظات -->
    <table class="client-info-table">
        <tr>
            <td style="width: 50%; vertical-align: top; text-align: right; line-height: 1.5;">
                <div class="section-title-orange">تفاصيل العميل:</div>
                <div style="font-weight: bold; font-size: 12px; color: #0f172a;">
                    {{ $sale->customer->name ?? ($sale->customer_name_text ?: '-') }}
                </div>
                @if($sale->customer_name_text && $sale->customer)
                    <div style="color: #ea580c; font-weight: bold; font-size: 10px;">
                        {{ $sale->customer_name_text }}
                    </div>
                @endif
                <div style="color: #64748b; font-size: 10px; margin-top: 2px;">
                    الموظف المسؤول: {{ $sale->user->name ?? '-' }}
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: left; line-height: 1.5;">
                <div class="section-title-slate">ملاحظات الفاتورة:</div>
                <div style="font-size: 10px; color: #475569;">
                    {{ $sale->notes ?: 'لا توجد ملاحظات' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- جدول الأصناف -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 44%;" class="text-right">الصنف التجاري</th>
                <th style="width: 12%;">الوحدة</th>
                <th style="width: 12%;">الكمية</th>
                <th style="width: 13%;">سعر الوحدة</th>
                <th style="width: 13%;" class="text-left">الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="text-right">
                        <strong>{{ $item->item->name ?? 'صنف غير معرف' }}</strong>
                        @if($item->length || $item->width)
                            <div style="font-size: 8.5px; color: #64748b; margin-top: 1px;">
                                المقاس: {{ $item->length ?: '-' }} × {{ $item->width ?: '-' }}
                            </div>
                        @endif
                    </td>
                    <td>{{ $item->itemUnit->unit->name ?? '-' }}</td>
                    <td style="font-weight: bold; color: #0f172a;">{{ $formatSmart($item->quantity) }}</td>
                    <td style="font-family: monospace; color: #475569;">{{ $formatSmart($item->unit_price) }}</td>
                    <td class="text-left" style="font-family: monospace; font-weight: bold; color: #0f172a;">
                        {{ $formatSmart($item->grand_total) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- شريط الإجماليات -->
    <table class="totals-container-table">
        <tr>
            <td style="text-align: right; vertical-align: middle;">
                <span style="color: #64748b; font-size: 10.5px;">الفرعي:</span>
                <span style="font-family: monospace; font-weight: bold; color: #0f172a; margin-right: 3px;">
                    {{ $formatSmart($sale->subtotal ?? 0) }}
                </span>

                    
                <span style="color: #e11d48; font-size: 10.5px;">الخصم:</span>
                <span style="font-family: monospace; font-weight: bold; color: #e11d48; margin-right: 3px;">
                    -{{ $formatSmart($sale->discount_amount ?? 0) }}
                </span>

                    
                <span style="color: #2563eb; font-size: 10.5px;">الضريبة:</span>
                <span style="font-family: monospace; font-weight: bold; color: #2563eb; margin-right: 3px;">
                    {{ $formatSmart($sale->tax_amount ?? 0) }}
                </span>
            </td>

            <td style="text-align: left; vertical-align: middle;">
                <div class="net-total-box">
                    <span style="font-size: 11px; color: #0f172a;">الصافي الفعلي:</span>
                    <span style="font-size: 14px; font-family: monospace; font-weight: bold; color: #ea580c; margin-right: 6px;">
                        {{ $formatSmart($sale->grand_total ?? 0) }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- التوقيعات الثلاثية -->
    <table class="signatures-table">
        <tr>
            <td style="width: 33.33%;">
                <div style="font-weight: bold; color: #334155; font-size: 10.5px;">توقيع المصمم</div>
                <div class="signature-line"></div>
            </td>
            <td style="width: 33.33%;">
                <div style="font-weight: bold; color: #334155; font-size: 10.5px;">توقيع المحاسب</div>
                <div class="signature-line"></div>
            </td>
            <td style="width: 33.33%;">
                <div style="font-weight: bold; color: #334155; font-size: 10.5px;">توقيع المستلم</div>
                <div class="signature-line"></div>
            </td>
        </tr>
    </table>

</body>
</html>