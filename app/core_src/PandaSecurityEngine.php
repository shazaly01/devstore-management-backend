<?php

namespace Panda\Core;

class PandaSecurityEngine
{
    /**
     * كاش مؤقت في الذاكرة لبصمة العتاد أثناء دورة حياة الطلب الواحد.
     */
    private static ?string $cachedHardwareId = null;

    /**
     * استخراج بصمة العتاد الفريدة للنظام (16 خانة مقسمة) بأداء فائق وثبات دائم.
     */
    public static function getHardwareIdentifier(?string $storagePath = null): string
    {
        if (self::$cachedHardwareId !== null) {
            return self::$cachedHardwareId;
        }

        $baseDir = self::resolveStorageDirectory($storagePath);
        $hwCacheFile = $baseDir . DIRECTORY_SEPARATOR . '.panda_hw';

        // 1. القراءة من الكاش الثابت للعتاد لتفادي استدعاء أوامر النظام البطيئة
        if (file_exists($hwCacheFile)) {
            $cachedContent = trim((string) @file_get_contents($hwCacheFile));
            if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $cachedContent)) {
                self::$cachedHardwareId = $cachedContent;
                return self::$cachedHardwareId;
            }
        }

        // 2. استخراج البصمة من عتاد الجهاز لأول مرة
        $rawIdentifier = '';

        if (PHP_OS_FAMILY === 'Windows') {
            // محاولة أولى وسريعة: فحص سجل الويندوز للـ 64-bit والـ 32-bit
            $regOutput = @shell_exec('reg query "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography" /v MachineGuid /reg:64 2>&1');
            if (!$regOutput || !preg_match('/MachineGuid\s+REG_SZ\s+([a-zA-Z0-9\-]+)/i', $regOutput, $matches)) {
                $regOutput = @shell_exec('reg query "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography" /v MachineGuid 2>&1');
            }

            if ($regOutput && preg_match('/MachineGuid\s+REG_SZ\s+([a-zA-Z0-9\-]+)/i', $regOutput, $matches)) {
                $rawIdentifier = trim($matches[1]);
            }

            // محاولة ثانية: استخراج UUID الجهاز عبر PowerShell في حال عدم توفر المفتاح
            if (empty($rawIdentifier)) {
                $psOutput = @shell_exec('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID" 2>&1');
                if ($psOutput && !str_contains($psOutput, 'is not recognized')) {
                    $rawIdentifier = trim((string) $psOutput);
                }
            }
        }

        // خيار الطوارئ الثابت
        if (empty($rawIdentifier)) {
            $rawIdentifier = php_uname('n') . '_' . php_uname('m');
        }

        $hash = strtoupper(hash('sha256', 'PANDA_SECURE_HW_' . $rawIdentifier));
        $chunks = str_split(substr($hash, 0, 16), 4);
        self::$cachedHardwareId = implode('-', $chunks);

        // تثبيت البصمة في ملف التخزين الدائم لضمان استقرارها للأبد
        if (is_dir($baseDir) && is_writable($baseDir)) {
            @file_put_contents($hwCacheFile, self::$cachedHardwareId, LOCK_EX);
        }

        return self::$cachedHardwareId;
    }

    /**
     * التحقق من التوقيع الرقمي للترخيص ومطابقته لعتاد الجهاز.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyLicenseKey(string $licenseKey, string $publicKey, ?string $storagePath = null): ?array
    {
        $cleanKey = preg_replace('/\s+/', '', $licenseKey);
        $parts = explode('.', $cleanKey);

        if (count($parts) !== 2) {
            return null;
        }

        [$payloadBase64, $signatureBase64] = $parts;

        $payloadJson = base64_decode($payloadBase64, true);
        $signature = base64_decode($signatureBase64, true);

        if ($payloadJson === false || $signature === false || empty($publicKey)) {
            return null;
        }

        $verified = openssl_verify($payloadJson, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return null;
        }

        $data = json_decode($payloadJson, true);
        if (!is_array($data) || empty($data['expires_at']) || empty($data['client_name']) || empty($data['hardware_id'])) {
            return null;
        }

        $currentHwId = self::getHardwareIdentifier($storagePath);
        if (!hash_equals($data['hardware_id'], $currentHwId)) {
            return null;
        }

        return $data;
    }

    /**
     * تقييم الفترة التجريبية ومكافحة التلاعب بالساعة أو السجلات بأداء عالٍ.
     *
     * @return array<string, mixed>
     */
    public static function evaluateTrial(int $trialDays, string $storagePath): array
    {
        $now = time();
        $currentHwId = self::getHardwareIdentifier($storagePath);
        $anchorTimestamp = self::getOrCreateTrialAnchor($now, $currentHwId, $storagePath);

        if ($anchorTimestamp === null) {
            return [
                'is_active' => false,
                'is_read_only' => true,
                'status' => 'trial_tampered',
                'days_remaining' => 0,
                'hardware_id' => $currentHwId,
                'message' => 'تم اكتشاف تلاعب في سجلات التثبيت. يرجى تفعيل البرنامج بكود ترخيص رسمي.',
            ];
        }

        // فحص التلاعب بساعة النظام مع تخفيف استهلاك القرص الصلب
        $clockFile = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . '.trial_clock';
        $lastSeenClock = 0;

        if (file_exists($clockFile)) {
            $lastSeenClock = (int) @file_get_contents($clockFile);
        }

        // مهلة سماح 15 دقيقة (900 ثانية) لتفادي مزامنة التوقيت عبر الإنترنت وتغيرات المناطق الزمنية
        if ($lastSeenClock > 0 && $now < ($lastSeenClock - 900)) {
            return [
                'is_active' => false,
                'is_read_only' => true,
                'status' => 'clock_tampered',
                'days_remaining' => 0,
                'hardware_id' => $currentHwId,
                'message' => 'تم اكتشاف تراجع في ساعة النظام. يرجى ضبط توقيت الجهاز للمتابعة.',
            ];
        }

        // تحديث ملف الساعة فقط إذا مر أكثر من 60 ثانية لتقليل عمليات الـ I/O على القرص
        if ($lastSeenClock === 0 || ($now > $lastSeenClock && ($now - $lastSeenClock) >= 60)) {
            @file_put_contents($clockFile, (string) $now, LOCK_EX);
        }

        $trialDurationSeconds = $trialDays * 86400;
        $trialExpiresAt = $anchorTimestamp + $trialDurationSeconds;

        if ($now >= $trialExpiresAt) {
            return [
                'is_active' => false,
                'is_read_only' => true,
                'status' => 'trial_expired',
                'days_remaining' => 0,
                'hardware_id' => $currentHwId,
                'expires_at' => date('Y-m-d H:i:s', $trialExpiresAt),
                'message' => "انتهت الفترة التجريبية المجانية ({$trialDays} يوماً). تم تحويل النظام لوضع القراءة فقط.",
            ];
        }

        $remainingSeconds = $trialExpiresAt - $now;
        $daysRemaining = (int) ceil($remainingSeconds / 86400);

        return [
            'is_active' => true,
            'is_read_only' => false,
            'status' => 'trial',
            'days_remaining' => max(1, $daysRemaining),
            'hardware_id' => $currentHwId,
            'expires_at' => date('Y-m-d H:i:s', $trialExpiresAt),
            'message' => "نسخة تجريبية مجانية: متبقي {$daysRemaining} يوم على انتهاء التجربة.",
        ];
    }

    /**
     * التحقق من مفتاح السيرفر السحابي (Online Master).
     */
    public static function isOnlineMasterValid(?string $masterKey, ?string $masterHash, string $host): bool
    {
        if (empty($masterKey) || empty($masterHash)) {
            return false;
        }

        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', ''])) {
            return false;
        }

        return hash_equals($masterHash, hash('sha256', $masterKey));
    }

    /**
     * تثبيت واسترجاع نقطة بداية التجربة من السجل والملف المشفر بشكل آمن.
     */
    private static function getOrCreateTrialAnchor(int $now, string $hardwareId, string $storagePath): ?int
    {
        $backupFile = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . '.trial_anchor';
        $anchorRaw = null;

        // 1. محاولة القراءة من سجل الويندوز (HKCU أولاً لتوفر الصلاحيات ثم HKLM)
        if (PHP_OS_FAMILY === 'Windows') {
            $regQueryHkcu = @shell_exec('reg query "HKEY_CURRENT_USER\Software\PandaAccounting" /v InstallAnchor 2>&1');
            if ($regQueryHkcu && preg_match('/InstallAnchor\s+REG_SZ\s+([^\r\n]+)/i', $regQueryHkcu, $matches)) {
                $anchorRaw = trim($matches[1]);
            }

            if (empty($anchorRaw)) {
                $regQueryHklm = @shell_exec('reg query "HKEY_LOCAL_MACHINE\Software\PandaAccounting" /v InstallAnchor 2>&1');
                if ($regQueryHklm && preg_match('/InstallAnchor\s+REG_SZ\s+([^\r\n]+)/i', $regQueryHklm, $matches)) {
                    $anchorRaw = trim($matches[1]);
                }
            }
        }

        // 2. القراءة من الملف الاحتياطي في مجلد التخزين
        if (empty($anchorRaw) && file_exists($backupFile)) {
            $anchorRaw = trim((string) @file_get_contents($backupFile));
        }

        // 3. إنشاء نقطة التثبيت لأول مرة إذا لم تكن موجودة
        if (empty($anchorRaw)) {
            $token = hash('sha256', $now . '_PANDA_' . $hardwareId);
            // تشفير البيانات بـ Base64 لضمان عدم تعارض الرموز مع سطر أوامر ويندوز
            $payload = base64_encode("{$now}:::{$token}");

            if (PHP_OS_FAMILY === 'Windows') {
                @shell_exec("reg add \"HKEY_CURRENT_USER\\Software\\PandaAccounting\" /v InstallAnchor /t REG_SZ /d \"{$payload}\" /f 2>&1");
                @shell_exec("reg add \"HKEY_LOCAL_MACHINE\\Software\\PandaAccounting\" /v InstallAnchor /t REG_SZ /d \"{$payload}\" /f 2>&1");
            }

            @file_put_contents($backupFile, $payload, LOCK_EX);
            return $now;
        }

        // 4. فك تشفير وفحص نقطة التثبيت (دعم التنسيق الجديد والتنسيقات القديمة التوافقية)
        $parsed = self::parseAnchorPayload($anchorRaw);
        if (!$parsed) {
            return null;
        }

        [$savedTimestamp, $savedToken] = $parsed;
        $expectedToken = hash('sha256', $savedTimestamp . '_PANDA_' . $hardwareId);

        if (!hash_equals($expectedToken, $savedToken)) {
            return null;
        }

        return (int) $savedTimestamp;
    }

    /**
     * فك شفرة حمولة نقطة التثبيت بدعم الصيغ القديمة والحديثة.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseAnchorPayload(string $raw): ?array
    {
        $data = $raw;

        // إذا كانت مشفرة بـ Base64
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && (str_contains($decoded, ':::') || str_contains($decoded, '|'))) {
            $data = $decoded;
        }

        if (str_contains($data, ':::')) {
            $parts = explode(':::', $data);
        } elseif (str_contains($data, '|')) {
            $parts = explode('|', $data);
        } else {
            return null;
        }

        if (count($parts) !== 2 || !is_numeric($parts[0])) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * إعادة ضبط وتطهير سجلات التجربة المعطوبة على أجهزة العملاء.
     */
    public static function resetTrialData(string $storagePath): bool
    {
        $backupFile = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . '.trial_anchor';
        $clockFile = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . '.trial_clock';
        $hwCacheFile = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . '.panda_hw';

        if (file_exists($backupFile)) {
            @unlink($backupFile);
        }
        if (file_exists($clockFile)) {
            @unlink($clockFile);
        }
        if (file_exists($hwCacheFile)) {
            @unlink($hwCacheFile);
        }

        self::$cachedHardwareId = null;

        if (PHP_OS_FAMILY === 'Windows') {
            @shell_exec('reg delete "HKEY_CURRENT_USER\Software\PandaAccounting" /v InstallAnchor /f 2>&1');
            @shell_exec('reg delete "HKEY_LOCAL_MACHINE\Software\PandaAccounting" /v InstallAnchor /f 2>&1');
        }

        return true;
    }

    /**
     * تحديد مجلد التخزين بدقة سواء كان داخل إطار Laravel أو خارجه.
     */
    private static function resolveStorageDirectory(?string $storagePath = null): string
    {
        if (!empty($storagePath) && is_dir($storagePath)) {
            return rtrim($storagePath, '/\\');
        }

        if (function_exists('storage_path')) {
            $path = storage_path('framework');
            if (is_dir($path)) {
                return $path;
            }
        }

        return sys_get_temp_dir();
    }
}