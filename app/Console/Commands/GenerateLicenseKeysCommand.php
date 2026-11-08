<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateLicenseKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:generate-keys {--path= : مسار مخصص لحفظ المفتاح الخاص}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'توليد زوج مفاتيح التشفير الرقمي (RSA 2048-bit) الخاص بنظام التراخيص';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('جاري إنشاء زوج المفاتيح الرقمية (RSA 2048-bit)...');

        // ضمان توفير ملف openssl.cnf متوافق مع نظام ويندوز و Wamp
        $cnfPath = $this->ensureOpenSslConfig();
        putenv("OPENSSL_CONF={$cnfPath}");

        $config = [
            'config' => $cnfPath,
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);

        if (!$res) {
            $this->error('فشل إنشاء زوج المفاتيح.');
            while ($msg = openssl_error_string()) {
                $this->error("تفاصيل الخطأ: {$msg}");
            }
            return self::FAILURE;
        }

        // استخراج المفتاح الخاص (Private Key)
        openssl_pkey_export($res, $privateKey, null, $config);

        // استخراج المفتاح العام (Public Key)
        $keyDetails = openssl_pkey_get_details($res);
        $publicKey = $keyDetails['key'];

        $defaultPath = storage_path('license_private.key');
        $targetPath = $this->option('path') ?: $defaultPath;

        File::put($targetPath, $privateKey);

        $this->newLine();
        $this->info("تم حفظ المفتاح الخاص (Private Key) بنجاح في:\n{$targetPath}");
        $this->warn('تنبيه أمني صارم: احتفظ بهذا المفتاح في جهازك الخاص فقط، ولا تضعه إطلاقاً في نسخة العميل.');

        $this->newLine();
        $this->line('ضع المفتاح العام التالي داخل config/license.php أو في ملف .env كقيمة لـ LICENSE_PUBLIC_KEY:');
        $this->newLine();
        $this->comment($publicKey);

        return self::SUCCESS;
    }

    /**
     * التحقق من مسار openssl.cnf أو إنشائه تلقائياً في بيئة ويندوز
     */
    private function ensureOpenSslConfig(): string
    {
        $phpDir = dirname(PHP_BINARY);

        $candidates = [
            getenv('OPENSSL_CONF'),
            $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $phpDir . DIRECTORY_SEPARATOR . 'openssl.cnf',
            'C:\\wamp64\\bin\\php\\' . basename($phpDir) . '\\extras\\ssl\\openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if ($path && File::exists($path)) {
                return $path;
            }
        }

        // إنشاء ملف إعدادات بديل ومستقر داخل مجلد storage تلقائياً
        $fallbackPath = storage_path('openssl_temp.cnf');
        if (!File::exists($fallbackPath)) {
            $minimalConfig = "[ req ]\ndefault_bits = 2048\ndistinguished_name = req_distinguished_name\n\n[ req_distinguished_name ]\n";
            File::put($fallbackPath, $minimalConfig);
        }

        return $fallbackPath;
    }
}