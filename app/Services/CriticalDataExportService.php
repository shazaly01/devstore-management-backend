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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Exception;

class CriticalDataExportService
{
    /**
     * المسار المستهدف لحفظ وتحديث ملف الإكسيل
     */
    protected string $targetDirectory;

    /**
     * اسم الملف الأساسي باللغة العربية ليظهر للعميل مباشرة على سطح المكتب
     */
    protected string $fileName = 'أرصدة_العملاء_والمخازن_للطوارئ.xlsx';

    /**
     * اسم الملف الاحتياطي في حال كان الملف الأساسي مفتوحاً ومقفلاً في Excel لحظة التحديث
     */
    protected string $lockedFallbackFileName = 'أرصدة_العملاء_والمخازن_للطوارئ_نسخة_محدثة.xlsx';

    public function __construct()
    {
        $this->targetDirectory = $this->resolveSafeBackupDirectory();
    }

    /**
     * تحديد مسار سطح المكتب بدقة وفق نظام التشغيل ومراعاة OneDrive وحسابات النظام
     */
    protected function resolveSafeBackupDirectory(): string
    {
        // 1. إذا تم تحديد مسار يدوي مخصص عبر ملف الإعدادات
        $customPath = config('services.emergency_backup.backup_path');
        if (!empty($customPath)) {
            return rtrim(trim((string) $customPath), '\\/');
        }

        // 2. في بيئة Windows: التوجيه لسطح المكتب الفعلي للمستخدم
        if (PHP_OS_FAMILY === 'Windows') {
            // أ. محاولة قراءة مسار سطح المكتب الفعلي مباشرة من سجل الويندوز (Registry)
            $registryDesktop = $this->resolveWindowsRegistryDesktop();
            if (!empty($registryDesktop)) {
                return $registryDesktop;
            }

            // ب. فحص متغير USERPROFILE القياسي مع مراعاة OneDrive
            $userProfile = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? null);
            if (!empty($userProfile) && stripos($userProfile, 'systemprofile') === false) {
                $desktopFromProfile = $this->findDesktopInsideUserFolder($userProfile);
                if (!empty($desktopFromProfile)) {
                    return $desktopFromProfile;
                }
            }

            // ج. في حال التشغيل تحت حساب نظام (systemprofile): البحث في مجلدات المستخدمين الفعلية
            $usersRoot = 'C:\\Users';
            if (File::isDirectory($usersRoot)) {
                $ignoredFolders = ['Public', 'Default', 'Default User', 'All Users'];
                foreach (File::directories($usersRoot) as $userDir) {
                    $folderName = basename($userDir);
                    if (in_array($folderName, $ignoredFolders, true)) {
                        continue;
                    }

                    $desktopPath = $this->findDesktopInsideUserFolder($userDir);
                    if (!empty($desktopPath)) {
                        return $desktopPath;
                    }
                }
            }
        }

        // 3. المسار الاحتياطي الافتراضي في بيئة السيرفر (Linux / Storage)
        return storage_path('app' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'excel');
    }

    /**
     * قراءة مسار سطح المكتب النشط من سجل الويندوز وتوسيع متغيرات البيئة فيه
     */
    protected function resolveWindowsRegistryDesktop(): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec('reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders" /v Desktop 2>nul');
        if (empty($output)) {
            return null;
        }

        if (preg_match('/Desktop\s+REG_(?:EXPAND_)?SZ\s+(.+)/i', $output, $matches)) {
            $rawPath = trim($matches[1]);
            $expandedPath = preg_replace_callback('/%([^%]+)%/', function ($varMatch) {
                $varName = $varMatch[1];
                return getenv($varName) ?: ($_SERVER[$varName] ?? $varMatch[0]);
            }, $rawPath);

            $cleanPath = rtrim($expandedPath, '\\/');
            if (
                !empty($cleanPath) &&
                stripos($cleanPath, 'systemprofile') === false &&
                File::isDirectory($cleanPath)
            ) {
                return $cleanPath;
            }
        }

        return null;
    }

    /**
     * التحقق من وجود مجلد سطح المكتب (سواء عبر OneDrive أو المحلي) داخل مجلد المستخدم
     */
    protected function findDesktopInsideUserFolder(string $userFolder): ?string
    {
        $cleanUserFolder = rtrim($userFolder, '\\/');

        $oneDriveDesktop = $cleanUserFolder . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR . 'Desktop';
        if (File::isDirectory($oneDriveDesktop)) {
            return $oneDriveDesktop;
        }

        $localDesktop = $cleanUserFolder . DIRECTORY_SEPARATOR . 'Desktop';
        if (File::isDirectory($localDesktop)) {
            return $localDesktop;
        }

        return null;
    }

    /**
     * توليد وتحديث ملف الإكسيل الحرج بأمان تام عبر مسار مؤقت إنجليزي ثم النسخ إلى سطح المكتب
     *
     * @return array
     * @throws Exception
     */
    public function generateExport(): array
    {
        if (!File::exists($this->targetDirectory)) {
            File::makeDirectory($this->targetDirectory, 0755, true, true);
        }

        $tempDir = storage_path('app' . DIRECTORY_SEPARATOR . 'temp');
        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true, true);
        }

        $tempFilePath = $tempDir . DIRECTORY_SEPARATOR . 'panda_emergency_backup_' . uniqid('', true) . '.xlsx';
        $primaryFilePath = $this->targetDirectory . DIRECTORY_SEPARATOR . $this->fileName;
        $fallbackFilePath = $this->targetDirectory . DIRECTORY_SEPARATOR . $this->lockedFallbackFileName;

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
            // الكتابة أولاً في مسار مؤقت إنجليزي لتجنب تعارض ZipArchive مع المسارات والأسماء العربية
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFilePath);

            // نسخ الملف المولد إلى سطح المكتب مع معالجة حالة فتح الملف في Excel لدى العميل
            $savedPath = $primaryFilePath;
            $savedFileName = $this->fileName;

            if (!@copy($tempFilePath, $primaryFilePath)) {
                if (@copy($tempFilePath, $fallbackFilePath)) {
                    $savedPath = $fallbackFilePath;
                    $savedFileName = $this->lockedFallbackFileName;
                } else {
                    throw new Exception("تعذر نسخ ملف الإكسيل إلى المسار المستهدف: {$primaryFilePath}");
                }
            }

            return [
                'success'   => true,
                'path'      => $savedPath,
                'directory' => $this->targetDirectory,
                'filename'  => $savedFileName,
                'date'      => now()->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            Log::error('فشل في تصدير ملف الإكسيل الحرج: ' . $e->getMessage());
            throw new Exception('تعذر حفظ ملف الإكسيل: ' . $e->getMessage());
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            if (File::exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
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

            $rowIndex++;
        }

        if ($rowIndex > 2) {
            $this->applyDataRangeStyle($sheet, 2, $rowIndex - 1, 8);
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

            $rowIndex++;
        }

        if ($rowIndex > 2) {
            $this->applyDataRangeStyle($sheet, 2, $rowIndex - 1, 8);
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

        $usedTitles = [];

        foreach ($stores as $store) {
            $safeTitle = trim(mb_substr(preg_replace('/[\\\\\/*?:\[\]]/', '', (string) $store->name), 0, 25));
            if (empty($safeTitle)) {
                $safeTitle = "مخزن {$store->id}";
            }

            if (in_array($safeTitle, $usedTitles, true)) {
                $safeTitle = mb_substr($safeTitle, 0, 22) . " ({$store->id})";
            }
            $usedTitles[] = $safeTitle;

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
            $sheet->setCellValueExplicit("C{$rowIndex}", $barcodeText, DataType::TYPE_STRING);
            $sheet->setCellValue("D{$rowIndex}", $item->name);
            $sheet->setCellValue("E{$rowIndex}", $item->category?->name ?? '—');
            $sheet->setCellValue("F{$rowIndex}", $item->baseUnit?->name ?? '—');
            $sheet->setCellValue("G{$rowIndex}", $unitPrice);
            $sheet->setCellValue("H{$rowIndex}", $quantity);
            $sheet->setCellValue("I{$rowIndex}", $totalSaleValue);
            $sheet->setCellValue("J{$rowIndex}", $status);

            $rowIndex++;
        }

        if ($rowIndex > 2) {
            $this->applyDataRangeStyle($sheet, 2, $rowIndex - 1, 10);
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
     * تنسيق نطاق البيانات بالكامل دفعة واحدة لتسريع التوليد وتقليل استهلاك الذاكرة
     */
    protected function applyDataRangeStyle($sheet, int $startRow, int $endRow, int $totalColumns): void
    {
        $lastColumnLetter = Coordinate::stringFromColumnIndex($totalColumns);
        $fullRange = "A{$startRow}:{$lastColumnLetter}{$endRow}";

        $sheet->getStyle($fullRange)->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'E5E7EB'],
                ],
            ],
        ]);

        for ($row = $startRow; $row <= $endRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(22);

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastColumnLetter}{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('F9FAFB');
            }
        }
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