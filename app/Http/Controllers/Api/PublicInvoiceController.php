<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\InvoicePdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicInvoiceController extends Controller
{
    protected InvoicePdfService $pdfService;

    public function __construct(InvoicePdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /**
     * استعراض ملف الفاتورة بصيغة PDF مباشرة في المتصفح أو الواتساب بناءً على الـ UUID
     */
    public function show(string $uuid): BinaryFileResponse
    {
        $sale = Sale::where('uuid', $uuid)->firstOrFail();

        $pdfPath = $this->pdfService->getOrGeneratePdfPath($sale);

        return response()->file($pdfPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-' . $sale->invoice_number . '.pdf"',
        ]);
    }
}