<?php

namespace App\Jobs;

use App\Services\CriticalDataExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportCriticalDataJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * هل التصدير إجباري ويتجاوز صمام الفحص الزمني (Throttle)؟
     */
    protected bool $isForce;

    /**
     * مدة تثبيت صمام التحديث بالدقائق (15 دقيقة كحد أدنى بين كل تحديث وآخر)
     */
    protected int $throttleMinutes;

    public function __construct(bool $isForce = false, int $throttleMinutes = 15)
    {
        $this->isForce = $isForce;
        $this->throttleMinutes = $throttleMinutes;
    }

    /**
     * تنفيذ الوظيفة وتوليد ملف الإكسيل مع التحقق من الصمام الزمني وصمام البيئة
     */
    public function handle(CriticalDataExportService $exportService): void
    {
        // 1. صمام الأمان: إيقاف المهمة فوراً إذا كانت الميزة معطلة في الإعدادات
        if (!config('services.emergency_backup.enabled')) {
            return;
        }

        $throttleKey = 'panda_critical_data_export_throttle_lock';

        // 2. التوقف الصامت دون تلويث السجلات إذا كان الملف محدثاً خلال الـ 15 دقيقة الماضية
        if (!$this->isForce && Cache::has($throttleKey)) {
            return;
        }

        // تأمين مهلة تنفيذ كافية لخادم PHP المحلي عند تعدد المخازن والأصناف
        @set_time_limit(120);

        try {
            $result = $exportService->generateExport();

            // قفل الصمام الزمني لمدة 15 دقيقة لمنع تكرار التوليد مع كل فاتورة متتالية
            Cache::put($throttleKey, now()->toDateTimeString(), now()->addMinutes($this->throttleMinutes));

            // تسجيل النجاح في وضع التطوير فقط لمنع تضخم ملف السجلات عند العميل
            if (config('app.debug')) {
                Log::info("الزناد الذكي: تم تحديث إكسيل الطوارئ بنجاح في المسار: {$result['path']}");
            }
        } catch (Throwable $e) {
            Log::error("فشل تنفيذ زناد تصدير الإكسيل الحرج: " . $e->getMessage());
        }
    }
}