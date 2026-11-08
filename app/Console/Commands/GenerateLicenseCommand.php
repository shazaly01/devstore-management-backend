<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateLicenseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:generate 
                            {client_name : اسم المنشأة أو العميل} 
                            {hardware_id : بصمة العتاد الخاصة بجهاز العميل (Hardware ID)} 
                            {expires_at : تاريخ انتهاء الصلاحية بصيغة YYYY-MM-DD} 
                            {--key= : مسار ملف المفتاح الخاص Private Key}
                            {--output= : مسار مخصص لحفظ ملف الترخيص}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'توليد كود ترخيص مشفر وموقع رقمياً مرتبط ببصمة عتاد الجهاز وحفظه في ملف';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clientName = trim($this->argument('client_name'));
        $hardwareId = strtoupper(trim($this->argument('hardware_id')));
        $expiresAtInput = trim($this->argument('expires_at'));

        // التحقق من صحة نسق بصمة العتاد (مثال: XXXX-XXXX-XXXX-XXXX)
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $hardwareId)) {
            $this->error('صيغة بصمة العتاد (Hardware ID) غير صحيحة. يجب أن تكون 16 خانة بالشكل: XXXX-XXXX-XXXX-XXXX');
            return self::FAILURE;
        }

        try {
            $expiresAt = Carbon::createFromFormat('Y-m-d', $expiresAtInput)->endOfDay();
        } catch (\Exception $e) {
            $this->error('صيغة التاريخ غير صحيحة. يجب أن تكون بصيغة: YYYY-MM-DD (مثال: 2027-01-01)');
            return self::FAILURE;
        }

        $privateKeyPath = $this->option('key') ?: storage_path('license_private.key');

        if (!File::exists($privateKeyPath)) {
            $this->error("ملف المفتاح الخاص غير موجود في المسار: {$privateKeyPath}");
            $this->line('يمكنك توليد المفاتيح أولاً عبر تنفيذ: php artisan license:generate-keys');
            return self::FAILURE;
        }

        $privateKey = File::get($privateKeyPath);

        // بناء حمولة البيانات وتضمين بصمة العتاد بداخلها
        $payload = [
            'client_name' => $clientName,
            'hardware_id' => $hardwareId,
            'expires_at' => $expiresAt->toDateTimeString(),
            'issued_at' => Carbon::now()->toDateTimeString(),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $signature = '';
        $signed = openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signed) {
            $this->error('فشل توقيع بيانات الترخيص رقمياً. تأكد من صحة المفتاح الخاص.');
            return self::FAILURE;
        }

        // تجميع كود التفعيل: [الحمولة مشفرة Base64] . [التوقيع مشفر Base64]
        $licenseKey = base64_encode($payloadJson) . '.' . base64_encode($signature);

        // تجهيز مسار وحفظ الملف
        $targetFilePath = $this->resolveOutputFilePath($clientName);
        File::put($targetFilePath, $licenseKey);

        $this->newLine();
        $this->info('====================================================');
        $this->info('           تم إصدار كود الترخيص وحفظه بنجاح          ');
        $this->info('====================================================');
        $this->line("العميل / المنشأة: <comment>{$clientName}</comment>");
        $this->line("بصمة العتاد المعتمدة: <comment>{$hardwareId}</comment>");
        $this->line("تاريخ الانتهاء:    <comment>{$expiresAt->toDateTimeString()}</comment>");
        $this->newLine();
        $this->info('تم حفظ كود الترخيص في الملف التالي:');
        $this->comment($targetFilePath);
        $this->newLine();
        $this->line('هذا الكود محمي برمجياً ولن يعمل إلا على الجهاز صاحب بصمة العتاد المحددة أعلاه.');
        $this->info('====================================================');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * تحديد مسار حفظ ملف الترخيص والتأكد من وجود المجلد
     */
    private function resolveOutputFilePath(string $clientName): string
    {
        $customOutput = $this->option('output');

        if (!empty($customOutput)) {
            $dir = dirname($customOutput);
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            return $customOutput;
        }

        $licensesDir = storage_path('licenses');
        if (!File::isDirectory($licensesDir)) {
            File::makeDirectory($licensesDir, 0755, true);
        }

        $safeClientName = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $clientName);
        $safeClientName = trim($safeClientName, '_');

        return $licensesDir . DIRECTORY_SEPARATOR . "license_{$safeClientName}.txt";
    }
}