<?php

if (ini_get('phar.readonly')) {
    echo "[!] تنبيه: خاصية phar.readonly مفعلة في php.ini. جاري إعادة التشغيل بوضع الكتابة...\n";
    $cmd = PHP_BINARY . ' -d phar.readonly=0 ' . escapeshellarg(__FILE__);
    passthru($cmd, $exitCode);
    exit($exitCode);
}

echo "====================================================\n";
echo "   بدء تجميع نواة أمان باندا للحسابات (Panda Core)   \n";
echo "====================================================\n";

$pharFile = __DIR__ . '/panda_core.phar';
$pubKeyFile = __DIR__ . '/panda_core.phar.pubkey';

// تحديد مسار مجلد النواة المصدري بدقة
$coreSourceDir = is_dir(__DIR__ . '/app/core_src')
    ? __DIR__ . '/app/core_src'
    : __DIR__ . '/core_src';

if (!is_dir($coreSourceDir)) {
    die("[-] خطأ: لم يتم العثور على مجلد النواة (app/core_src أو core_src)!\n");
}

// تحديد مسار المفتاح الخاص (التحقق من storage أولاً ثم جذر المشروع)
$privateKeyPath = file_exists(__DIR__ . '/storage/license_private.key')
    ? __DIR__ . '/storage/license_private.key'
    : __DIR__ . '/license_private.key';

if (!file_exists($privateKeyPath)) {
    die("[-] خطأ: المفتاح الخاص license_private.key غير موجود في storage/ أو المجلد الرئيسي!\n");
}

// تنظيف النسخ السابقة إن وجدت لضمان بناء نظيف 100%
if (file_exists($pharFile)) {
    unlink($pharFile);
}
if (file_exists($pubKeyFile)) {
    unlink($pubKeyFile);
}

// إنشاء ملف Autoload داخلي لحزمة PHAR لضمان تحميل الكلاسات فورياً داخل الأرشيف
$internalAutoload = "<?php\n\n"
    . "spl_autoload_register(function (\$class) {\n"
    . "    if (str_starts_with(\$class, 'Panda\\\\Core\\\\')) {\n"
    . "        \$relative = substr(\$class, 11);\n"
    . "        \$file = __DIR__ . '/' . str_replace('\\\\', '/', \$relative) . '.php';\n"
    . "        if (file_exists(\$file)) {\n"
    . "            require_once \$file;\n"
    . "        }\n"
    . "    }\n"
    . "});\n";

file_put_contents($coreSourceDir . '/autoload.php', $internalAutoload);

echo "[*] جاري ضغط وحزم ملفات الأمان من: {$coreSourceDir}\n";
$phar = new Phar($pharFile, 0, 'panda_core.phar');
$phar->startBuffering();

// إضافة ملفات النواة إلى الحزمة
$phar->buildFromDirectory($coreSourceDir);
$phar->setStub("<?php Phar::mapPhar('panda_core.phar'); require_once 'phar://panda_core.phar/PandaSecurityEngine.php'; __HALT_COMPILER(); ?>");
$phar->stopBuffering();

// إزالة ملف autoload المؤقت من المجلد المصدري
@unlink($coreSourceDir . '/autoload.php');

echo "[*] جاري ختم وتوقيع الحزمة رقمياً عبر OpenSSL (RSA-SHA256)...\n";
$privateKeyResource = openssl_pkey_get_private(file_get_contents($privateKeyPath));
if (!$privateKeyResource) {
    die("[-] خطأ: فشل قراءة المفتاح الخاص license_private.key.\n");
}

$phar->setSignatureAlgorithm(Phar::OPENSSL, file_get_contents($privateKeyPath));

// استخراج وتصدير المفتاح العام المصاحب للحزمة مباشرة من المفتاح الخاص
$keyDetails = openssl_pkey_get_details($privateKeyResource);
$publicKey = $keyDetails['key'];
file_put_contents($pubKeyFile, $publicKey);

echo "\n====================================================\n";
echo "       تم إنشاء وتوقيع حزمة النواة بنجاح!          \n";
echo "====================================================\n";
echo "ملف الحزمة المشفر:   panda_core.phar (" . round(filesize($pharFile) / 1024, 2) . " KB)\n";
echo "مفتاح التحقق العام: panda_core.phar.pubkey\n\n";
echo "[+] مسار المفتاح الخاص المستخدم: {$privateKeyPath}\n";
echo "[+] تم استخراج مفتاح التحقق العام بنجاح بجوار ملف الـ PHAR.\n";