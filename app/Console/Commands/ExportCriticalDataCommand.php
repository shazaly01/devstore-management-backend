<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CriticalDataExportService;
use App\Jobs\ExportCriticalDataJob;
use Throwable;

class ExportCriticalDataCommand extends Command
{
    /**
     * اسم ومعاملات الأمر البرمجي
     *
     * @var string
     */
    protected $signature = 'panda:export-critical-data {--queue : إرسال العملية إلى طابور المعالجة بالخلفية بدلاً من التنفيذ المباشر}';

    /**
     * وصف الأمر البرمجي
     *
     * @var string
     */
    protected $description = 'تصدير وتحديث ملف الإكسيل الحرج (أرصدة العملاء والموردين وأصناف المخازن وأسعارها) لحالات الطوارئ';

    /**
     * تنفيذ الأمر البرمجي
     */
    public function handle(CriticalDataExportService $exportService): int
    {
        $this->info('=== بدء عملية تصدير البيانات الحرجة لمنظومة باندا الحسابية ===');

        if ($this->option('queue')) {
            ExportCriticalDataJob::dispatch();
            $this->info('تم إرسال مهمة تصدير البيانات بنجاح إلى طابور المعالجة الخلفي.');
            return Command::SUCCESS;
        }

        try {
            $this->output->write('جاري استخراج البيانات وبناء أوراق الإكسيل... ');
            
            $result = $exportService->generateExport();

            $this->newLine();
            $this->info("تم إنشاء وتحديث ملف الإكسيل بنجاح تام.");
            $this->table(
                ['البند', 'القيمة'],
                [
                    ['اسم الملف', $result['filename']],
                    ['المجلد المستهدف', $result['directory']],
                    ['المسار الكامل', $result['path']],
                    ['تاريخ ووقت التحديث', $result['date']],
                ]
            );

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error("حدث خطأ أثناء تصدير ملف الإكسيل: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}