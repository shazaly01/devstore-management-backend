<?php

namespace App\Services;

use App\Models\SystemLicense;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Panda\Core\PandaSecurityEngine;

class LicenseService
{
    /**
     * كاش محلي في الذاكرة لمنع تكرار الفحص داخل نفس دورة حياة الطلب الواحد (In-Memory Request Cache).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $runtimeStatusCache = null;

    /**
     * تحميل نواة الأمان ذاتياً في الذاكرة لضمان العمل تحت كافة مسارات التشغيل.
     */
    private static function ensureCoreLoaded(): void
    {
        if (class_exists(PandaSecurityEngine::class)) {
            return;
        }

        $pharPath = base_path('panda_core.phar');
        $pubKeyPath = base_path('panda_core.phar.pubkey');

        // التحقق من وجود الحزمة والمفتاح العام المرافق معاً لتجنب أي انهيار
        if (file_exists($pharPath) && file_exists($pubKeyPath)) {
            require_once $pharPath;
            return;
        }

        // مسار التطوير الفعلي داخل مجلد التطبيق
        $devCorePath = base_path('app/core_src/PandaSecurityEngine.php');
        if (file_exists($devCorePath)) {
            require_once $devCorePath;
            return;
        }

        // مسار احتياطي إضافي في حال التواجد في الجذر
        $rootCorePath = base_path('core_src/PandaSecurityEngine.php');
        if (file_exists($rootCorePath)) {
            require_once $rootCorePath;
        }
    }

    /**
     * تفريغ كاش حالة الترخيص فورياً عند التفعيل أو إعادة الضبط.
     */
    public static function clearStatusCache(): void
    {
        self::$runtimeStatusCache = null;
        try {
            Cache::forget('panda_system_license_status');
        } catch (\Throwable $e) {
            // تجاهل أي خطأ في حال عدم جاهزية درايفر الكاش
        }
    }

    /**
     * التحقق مما إذا كان النظام يعمل بنسخة أونلاين سحابية معتمدة.
     */
    public function isOnlineMasterActive(): bool
    {
        self::ensureCoreLoaded();

        $masterKey = Config::get('license.online_master_key');
        $masterHash = Config::get('license.online_master_hash');
        $host = request()?->getHost() ?? '';

        return PandaSecurityEngine::isOnlineMasterValid($masterKey, $masterHash, $host);
    }

    /**
     * استخراج بصمة العتاد الفريدة للجهاز عبر النواة المشفرة.
     */
    public function getHardwareIdentifier(): string
    {
        self::ensureCoreLoaded();

        return PandaSecurityEngine::getHardwareIdentifier(storage_path('framework'));
    }

    /**
     * جلب سجل الترخيص الفعال حالياً في قاعدة البيانات.
     */
    public function getActiveLicense(): ?SystemLicense
    {
        return SystemLicense::where('is_active', true)
            ->latest('id')
            ->first();
    }

    /**
     * فحص الحالة الشاملة للترخيص وإرجاع تقرير الحالة للواجهة الأمامية بأداء عالٍ.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        // 1. فحص الكاش الداخلي للطلب
        if (self::$runtimeStatusCache !== null) {
            return self::$runtimeStatusCache;
        }

        // 2. فحص كاش التطبيق المؤقت (15 ثانية) لتقليل الضغط أثناء عمليات الكاشير
        try {
            $cachedStatus = Cache::get('panda_system_license_status');
            if (is_array($cachedStatus)) {
                self::$runtimeStatusCache = $cachedStatus;
                return self::$runtimeStatusCache;
            }
        } catch (\Throwable $e) {
            // المتابعة في حال تعطل الكاش
        }

        self::ensureCoreLoaded();

        $storagePath = storage_path('framework');
        $currentHardwareId = $this->getHardwareIdentifier();

        // 1. فحص النسخة السحابية الأونلاين
        if ($this->isOnlineMasterActive()) {
            $status = [
                'is_active' => true,
                'is_read_only' => false,
                'status' => 'online_unlimited',
                'days_remaining' => 9999,
                'client_name' => 'Online Cloud Instance',
                'hardware_id' => $currentHardwareId,
                'expires_at' => null,
                'message' => 'النظام يعمل بالسيرفر السحابي المباشر.',
            ];

            return $this->cacheAndReturnStatus($status);
        }

        $license = $this->getActiveLicense();
        $now = Carbon::now();

        // 2. تقييم الفترة التجريبية (14 يوماً) في حال عدم وجود ترخيص مثبت
        if (!$license) {
            $trialDays = (int) Config::get('license.trial_days', 14);
            $trialResult = PandaSecurityEngine::evaluateTrial($trialDays, $storagePath);

            $status = [
                'is_active' => $trialResult['is_active'],
                'is_read_only' => $trialResult['is_read_only'],
                'status' => $trialResult['status'],
                'days_remaining' => $trialResult['days_remaining'],
                'client_name' => 'نسخة تجريبية',
                'hardware_id' => $trialResult['hardware_id'],
                'expires_at' => $trialResult['expires_at'] ?? null,
                'message' => $trialResult['message'],
            ];

            return $this->cacheAndReturnStatus($status);
        }

        // 3. فحص التراجع الزمني لمنع التلاعب بساعة النظام (مهلة 15 دقيقة)
        if ($license->max_seen_at && $now->lt($license->max_seen_at->subMinutes(15))) {
            $status = [
                'is_active' => false,
                'is_read_only' => true,
                'status' => 'clock_tampered',
                'days_remaining' => 0,
                'client_name' => $license->client_name,
                'hardware_id' => $currentHardwareId,
                'expires_at' => $license->expires_at->toDateTimeString(),
                'message' => 'تم اكتشاف تراجع في توقيت النظام المحلي. يرجى ضبط ساعة الجهاز للمتابعة.',
            ];

            return $this->cacheAndReturnStatus($status);
        }

        // 4. التحقق من التوقيع الرقمي ومطابقة عتاد الجهاز عبر النواة
        $publicKey = Config::get('license.public_key', '');
        $parsed = PandaSecurityEngine::verifyLicenseKey($license->license_key, $publicKey, $storagePath);

        if (!$parsed) {
            $status = [
                'is_active' => false,
                'is_read_only' => true,
                'status' => 'corrupted',
                'days_remaining' => 0,
                'client_name' => $license->client_name,
                'hardware_id' => $currentHardwareId,
                'expires_at' => $license->expires_at->toDateTimeString(),
                'message' => 'بيانات الترخيص المسجلة غير صالحة، أو لا تتطابق مع عتاد هذا الجهاز.',
            ];

            return $this->cacheAndReturnStatus($status);
        }

        // تحديث أعلى تاريخ وصول مسجل (مرة كل 5 دقائق كحد أدنى لتجنب الكتابة الدائمة في الداتابيز)
        if (!$license->max_seen_at || $now->diffInMinutes($license->max_seen_at) >= 5) {
            $license->update([
                'max_seen_at' => $now,
                'last_verified_at' => $now,
            ]);
        }

        // 5. فحص تاريخ الانتهاء
        if ($now->greaterThanOrEqualTo($license->expires_at)) {
            $status = [
                'is_active' => false,
                'is_read_only' => true,
                'status' => 'expired',
                'days_remaining' => 0,
                'client_name' => $license->client_name,
                'hardware_id' => $currentHardwareId,
                'expires_at' => $license->expires_at->toDateTimeString(),
                'message' => 'انتهت فترة الترخيص المحددة. النظام حالياً في وضع القراءة فقط.',
            ];

            return $this->cacheAndReturnStatus($status);
        }

        // 6. حساب الأيام المتبقية
        $daysRemaining = (int) $now->diffInDays($license->expires_at, false);
        $warningDays = (int) Config::get('license.warning_days_before_expiry', 7);

        $status = [
            'is_active' => true,
            'is_read_only' => false,
            'status' => ($daysRemaining <= $warningDays) ? 'grace_period' : 'active',
            'days_remaining' => max(0, $daysRemaining),
            'client_name' => $license->client_name,
            'hardware_id' => $currentHardwareId,
            'expires_at' => $license->expires_at->toDateTimeString(),
            'message' => ($daysRemaining <= $warningDays)
                ? "تنبيه: متبقي {$daysRemaining} يوم على انتهاء الترخيص الحالي."
                : 'الترخيص سارٍ ويعمل بكامل الصلاحيات.',
        ];

        return $this->cacheAndReturnStatus($status);
    }

    /**
     * حفظ الحالة في الكاش السريع وإرجاعها.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function cacheAndReturnStatus(array $status): array
    {
        self::$runtimeStatusCache = $status;

        try {
            Cache::put('panda_system_license_status', $status, now()->addSeconds(15));
        } catch (\Throwable $e) {
            // تجاوز في حال فشل الاتصال بالكاش
        }

        return $status;
    }

    /**
     * التحقق مما إذا كان النظام في وضع القراءة فقط.
     */
    public function isReadOnly(): bool
    {
        return (bool) $this->getStatus()['is_read_only'];
    }

    /**
     * قفل العمليات البرمجية والتعديلات إذا كان الترخيص غير نشط.
     */
    public function ensureActiveLicense(): void
    {
        $status = $this->getStatus();

        if ($status['is_read_only']) {
            abort(response()->json([
                'message' => $status['message'],
                'license_status' => $status,
            ], 402));
        }
    }

    /**
     * تفعيل ترخيص جديد وتخزينه في قاعدة البيانات.
     */
    public function activateLicense(string $licenseKey): array
    {
        self::ensureCoreLoaded();

        $cleanLicenseKey = preg_replace('/\s+/', '', $licenseKey);
        $publicKey = Config::get('license.public_key', '');
        $storagePath = storage_path('framework');

        $parsed = PandaSecurityEngine::verifyLicenseKey($cleanLicenseKey, $publicKey, $storagePath);

        if (!$parsed) {
            abort(response()->json([
                'message' => 'كود التفعيل غير صالح، أو غير مخصص لمواصفات هذا الجهاز.',
            ], 422));
        }

        $expiresAt = Carbon::parse($parsed['expires_at']);
        $now = Carbon::now();

        if ($now->greaterThanOrEqualTo($expiresAt)) {
            abort(response()->json([
                'message' => 'كود التفعيل المدخل منتهي الصلاحية مسبقاً.',
            ], 422));
        }

        SystemLicense::where('is_active', true)->update(['is_active' => false]);

        $newLicense = SystemLicense::create([
            'client_name' => $parsed['client_name'],
            'license_key' => $cleanLicenseKey,
            'expires_at' => $expiresAt,
            'max_seen_at' => $now,
            'last_verified_at' => $now,
            'is_active' => true,
            'meta_data' => array_merge($parsed['meta'] ?? [], [
                'hardware_id' => $parsed['hardware_id'],
            ]),
        ]);

        // تطهير الكاش فوراً لتفعيل الحالة الجديدة لحظياً
        self::clearStatusCache();

        return [
            'success' => true,
            'message' => 'تم تفعيل الترخيص بنجاح.',
            'license' => [
                'client_name' => $newLicense->client_name,
                'hardware_id' => $parsed['hardware_id'],
                'expires_at' => $newLicense->expires_at->toDateTimeString(),
                'days_remaining' => (int) $now->diffInDays($newLicense->expires_at, false),
            ],
        ];
    }

    /**
     * إصلاح وتطهير سجلات الفترة التجريبية التالفة.
     */
    public function repairTrial(): bool
    {
        self::ensureCoreLoaded();
        self::clearStatusCache();

        return PandaSecurityEngine::resetTrialData(storage_path('framework'));
    }
}