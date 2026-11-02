<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Exception;

class InvoicePdfService
{
    /**
     * المسار النسبي لمجلد حفظ الفواتير داخل قرص public
     */
    protected const INVOICES_DIRECTORY = 'invoices';

    /**
     * الحصول على المسار الفيزيائي لملف الـ PDF مع توليده وتخزينه تلقائياً إذا لم يكن موجوداً
     */
    public function getOrGeneratePdfPath(Sale $sale): string
    {
        $fileName = 'invoice-' . $sale->uuid . '.pdf';
        $relativePath = self::INVOICES_DIRECTORY . '/' . $fileName;
        $disk = Storage::disk('public');

        if (!$disk->exists($relativePath)) {
            $this->generateAndStore($sale, $relativePath);
        }

        return $disk->path($relativePath);
    }

    /**
     * توليد محتوى ملف الفاتورة بصيغة PDF وحفظه في السيرفر
     */
    public function generateAndStore(Sale $sale, ?string $relativePath = null): string
    {
        if (empty($relativePath)) {
            $relativePath = self::INVOICES_DIRECTORY . '/invoice-' . $sale->uuid . '.pdf';
        }

        // تحميل العلاقات المطلوبة لعرض كافة تفاصيل الفاتورة
        $sale->loadMissing([
            'items.item',
            'items.itemUnit.unit',
            'store',
            'customer',
            'user',
            'treasury',
            'bank'
        ]);

        // تحضير الـ HTML من قالب Blade
        $html = view('invoices.sale', ['sale' => $sale])->render();

        // إعداد محرك mPDF لدعم كامل ودقيق للغة العربية والاتجاه من اليمين لليسار
        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'orientation'       => 'P',
            'margin_left'       => 10,
            'margin_right'      => 10,
            'margin_top'        => 12,
            'margin_bottom'     => 12,
            'autoScriptToLang'  => true,
            'autoLangToFont'    => true,
            'tempDir'           => storage_path('app/temp-mpdf'),
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML($html);

        $pdfBinary = $mpdf->Output('', 'S');

        // التأكد من وجود مجلد الحفظ في السيرفر وتخزين الملف
        $disk = Storage::disk('public');
        if (!$disk->exists(self::INVOICES_DIRECTORY)) {
            $disk->makeDirectory(self::INVOICES_DIRECTORY);
        }

        $disk->put($relativePath, $pdfBinary);

        return $disk->path($relativePath);
    }

    /**
     * حذف ملف الـ PDF من السيرفر عند تعديل الفاتورة لضمان إعادة توليدها بالبيانات الجديدة
     */
    public function clearPdfCache(Sale $sale): void
    {
        if (!empty($sale->uuid)) {
            $relativePath = self::INVOICES_DIRECTORY . '/invoice-' . $sale->uuid . '.pdf';
            $disk = Storage::disk('public');
            if ($disk->exists($relativePath)) {
                $disk->delete($relativePath);
            }
        }
    }
}