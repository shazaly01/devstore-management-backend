<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Store;
use App\Models\Item;
use App\Models\ItemStock;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Exception;

class CriticalDataExportService
{
    /**
     * المسار المستهدف لحفظ وتحديث ملف الإكسيل
     */
    protected string $targetDirectory;

    /**
     * اسم الملف واضحاً باللغة العربية ليظهر للعميل مباشرة على سطح المكتب
     */
    protected string $fileName = 'أرصدة_العملاء_والمخازن_للطوارئ.xlsx';

    public function __construct()
    {
        $this->targetDirectory = $this->resolveSafeBackupDirectory();
    }

    /**
     * تحديد مسار سطح المكتب بدقة وفق نظام التشغيل ومراعاة OneDrive
     */
    protected function resolveSafeBackupDirectory(): string
    {
        // 1. إذا تم تحديد مسار يدوي مخصص داخل ملف .env
        $customPath = env('CRITICAL_DATA_BACKUP_PATH');
        if (!empty($customPath)) {
            return rtrim($customPath, '\\/');
        }

        // 2. في بيئة Windows: التوجيه لسطح المكتب مباشرة مع دعم OneDrive
        if (PHP_OS_FAMILY === 'Windows') {
            $userProfile = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? null);

            if (!empty($userProfile)) {
                $oneDriveDesktop = $userProfile . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR . 'Desktop';
                if (File::isDirectory($oneDriveDesktop)) {
                    return $oneDriveDesktop;
                }

                $localDesktop = $userProfile . DIRECTORY_SEPARATOR . 'Desktop';
                if (File::isDirectory($localDesktop)) {
                    return $localDesktop;
                }
            }
        }

        // 3. المسار الاحتياطي الافتراضي في بيئة السيرفر (Linux / Storage)
        return storage_path('app' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'excel');
    }

    /**
     * توليد وتحديث ملف الإكسيل الحرج
     *
     * @return array
     * @throws Exception
     */
    public function generateExport(): array
    {
        if (!File::exists($this->targetDirectory)) {
            File::makeDirectory($this->targetDirectory, 0755, true, true);
        }

        $filePath = $this->targetDirectory . DIRECTORY_SEPARATOR . $this->fileName;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        // 1. إنشاء شيت أرصدة العملاء
        $this->buildCustomersSheet($spreadsheet);

        // 2. إنشاء شيت أرصدة الموردين
        $this->buildSuppliersSheet($spreadsheet);

        // 3. إنشاء شيت مستقل لكل مخزن بكامل أصنافه وأسعار البيع
        $this->buildStoresSheets($spreadsheet);

        // ضبط التركيز على الشيت الأول عند فتح الملف
        $spreadsheet->setActiveSheetIndex(0);

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            return [
                'success'   => true,
                'path'      => $filePath,
                'directory' => $this->targetDirectory,
                'filename'  => $this->fileName,
                'date'      => now()->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            Log::error('فشل في تصدير ملف الإكسيل الحرج: ' . $e->getMessage());
            throw new Exception('تعذر حفظ ملف الإكسيل: ' . $e->getMessage());
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * بناء شيت العملاء
     */
    protected function buildCustomersSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('أرصدة العملاء');
        $sheet->setRightToLeft(true);

        $headers = [
            'م',
            'معرف العميل',
            'اسم العميل',
            'رقم الهاتف',
            'البريد الإلكتروني',
            'الرصيد الافتتاحي',
            'الرصيد الحالي',
            'طبيعة الرصيد'
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->applyHeaderStyle($sheet, 'A1:H1', '1E40AF');

        $customers = Customer::orderBy('name', 'asc')->get();
        $rowIndex = 2;

        foreach ($customers as $index => $customer) {
            $currentBalance = (float) $customer->current_balance;
            $nature = 'متزن';
            if ($currentBalance > 0) {
                $nature = 'مدين (مطلوب منه)';
            } elseif ($currentBalance < 0) {
                $nature = 'دائن (له رصيد)';
            }

            $sheet->setCellValue("A{$rowIndex}", $index + 1);
            $sheet->setCellValue("B{$rowIndex}", $customer->id);
            $sheet->setCellValue("C{$rowIndex}", $customer->name);
            $sheet->setCellValue("D{$rowIndex}", $customer->phone ?? '—');
            $sheet->setCellValue("E{$rowIndex}", $customer->email ?? '—');
            $sheet->setCellValue("F{$rowIndex}", (float) $customer->opening_balance);
            $sheet->setCellValue("G{$rowIndex}", $currentBalance);
            $sheet->setCellValue("H{$rowIndex}", $nature);

            $this->applyRowStyle($sheet, $rowIndex, 8, $index % 2 === 0);
            $rowIndex++;
        }

        $this->autoFitColumns($sheet, range('A', 'H'));
    }

    /**
     * بناء شيت الموردين
     */
    protected function buildSuppliersSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('أرصدة الموردين');
        $sheet->setRightToLeft(true);

        $headers = [
            'م',
            'معرف المورد',
            'اسم المورد',
            'رقم الهاتف',
            'الرقم الضريبي',
            'الرصيد الافتتاحي',
            'الرصيد الحالي',
            'طبيعة الرصيد'
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->applyHeaderStyle($sheet, 'A1:H1', '065F46');

        $suppliers = Supplier::orderBy('name', 'asc')->get();
        $rowIndex = 2;

        foreach ($suppliers as $index => $supplier) {
            $currentBalance = (float) $supplier->current_balance;
            $nature = 'متزن';
            if ($currentBalance > 0) {
                $nature = 'دائن (له مستحقات)';
            } elseif ($currentBalance < 0) {
                $nature = 'مدين (مطلوب منه)';
            }

            $sheet->setCellValue("A{$rowIndex}", $index + 1);
            $sheet->setCellValue("B{$rowIndex}", $supplier->id);
            $sheet->setCellValue("C{$rowIndex}", $supplier->name);
            $sheet->setCellValue("D{$rowIndex}", $supplier->phone ?? '—');
            $sheet->setCellValue("E{$rowIndex}", $supplier->tax_number ?? '—');
            $sheet->setCellValue("F{$rowIndex}", (float) $supplier->opening_balance);
            $sheet->setCellValue("G{$rowIndex}", $currentBalance);
            $sheet->setCellValue("H{$rowIndex}", $nature);

            $this->applyRowStyle($sheet, $rowIndex, 8, $index % 2 === 0);
            $rowIndex++;
        }

        $this->autoFitColumns($sheet, range('A', 'H'));
    }

    /**
     * بناء أوراق عمل مستقلة لكل مخزن بالأصناف وأسعار البيع والكميات
     */
    protected function buildStoresSheets(Spreadsheet $spreadsheet): void
    {
        $stores = Store::orderBy('id', 'asc')->get();

        $items = Item::with(['category', 'baseUnit', 'units', 'barcodes'])
            ->where('item_type', 'product')
            ->orderBy('name', 'asc')
            ->get();

        if ($stores->isEmpty()) {
            $this->buildSingleItemsSheet($spreadsheet, 'الأصناف والأسعار', $items, null);
            return;
        }

        foreach ($stores as $store) {
            $safeTitle = mb_substr(preg_replace('/[\\\\\/*?:\[\]]/', '', $store->name), 0, 28);
            if (empty(trim($safeTitle))) {
                $safeTitle = "مخزن {$store->id}";
            }

            $this->buildSingleItemsSheet($spreadsheet, $safeTitle, $items, $store->id);
        }
    }

    /**
     * بناء شيت أصناف لمخزن محدد يتضمن الباركود وسعر البيع فقط
     */
    protected function buildSingleItemsSheet(Spreadsheet $spreadsheet, string $sheetTitle, $items, ?int $storeId): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->setRightToLeft(true);

        $headers = [
            'م',
            'معرف الصنف',
            'الباركود',
            'اسم الصنف',
            'التصنيف',
            'الوحدة الأساسية',
            'سعر البيع',
            'الكمية المتوفرة',
            'إجمالي قيمة البيع',
            'حالة التوفر'
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->applyHeaderStyle($sheet, 'A1:J1', '374151');

        $stockMap = [];
        if ($storeId) {
            $stockMap = ItemStock::where('store_id', $storeId)
                ->pluck('current_quantity', 'item_id')
                ->toArray();
        }

        $rowIndex = 2;
        foreach ($items as $index => $item) {
            $baseUnitConfig = $item->units ? $item->units->firstWhere('unit_id', $item->base_unit_id) : null;
            $unitPrice = $baseUnitConfig ? (float) $baseUnitConfig->price : 0.00;

            $barcodeText = '—';
            if ($item->barcodes && $item->barcodes->isNotEmpty()) {
                $barcodeText = $item->barcodes->pluck('barcode')->implode(' | ');
            }

            $quantity = $storeId ? (float) ($stockMap[$item->id] ?? 0.00) : 0.00;
            $totalSaleValue = round($quantity * $unitPrice, 2);

            $status = $quantity > 0 ? 'متوفر' : 'منتهي الرصيد (للتسعير فقط)';

            $sheet->setCellValue("A{$rowIndex}", $index + 1);
            $sheet->setCellValue("B{$rowIndex}", $item->id);
            $sheet->setCellValueExplicit("C{$rowIndex}", $barcodeText, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("D{$rowIndex}", $item->name);
            $sheet->setCellValue("E{$rowIndex}", $item->category->name ?? '—');
            $sheet->setCellValue("F{$rowIndex}", $item->baseUnit->name ?? '—');
            $sheet->setCellValue("G{$rowIndex}", $unitPrice);
            $sheet->setCellValue("H{$rowIndex}", $quantity);
            $sheet->setCellValue("I{$rowIndex}", $totalSaleValue);
            $sheet->setCellValue("J{$rowIndex}", $status);

            $this->applyRowStyle($sheet, $rowIndex, 10, $index % 2 === 0);
            $rowIndex++;
        }

        $this->autoFitColumns($sheet, range('A', 'J'));
    }

    /**
     * تنسيق ترويسة الجدول وتجميد الصف الأول
     */
    protected function applyHeaderStyle($sheet, string $cellRange, string $colorHex): void
    {
        $sheet->freezePane('A2');

        $sheet->getStyle($cellRange)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $colorHex],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'D1D5DB'],
                ],
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(28);
    }

    /**
     * تنسيق أسطر البيانات ومحاذاتها
     */
    protected function applyRowStyle($sheet, int $rowIndex, int $totalColumns, bool $isEven): void
    {
        $lastColumnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalColumns);
        $range = "A{$rowIndex}:{$lastColumnLetter}{$rowIndex}";

        $bgColor = $isEven ? 'F9FAFB' : 'FFFFFF';

        $sheet->getStyle($range)->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $bgColor],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'E5E7EB'],
                ],
            ],
        ]);

        $sheet->getRowDimension($rowIndex)->setRowHeight(22);
    }

    /**
     * محاذاة العرض التلقائي للأعمدة
     */
    protected function autoFitColumns($sheet, array $columns): void
    {
        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}