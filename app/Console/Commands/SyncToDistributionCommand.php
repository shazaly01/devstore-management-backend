<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;

class SyncToDistributionCommand extends Command
{
    /**
     * اسم وتوقيع الأمر في سطر الأوامر
     *
     * @var string
     */
    protected $signature = 'app:sync-dist {--target=C:\PandaDistribution\app : مسار مجلد التوزيع المستهدف}';

    /**
     * وصف وظيفة الأمر
     *
     * @var string
     */
    protected $description = 'مزامنة كود المشروع الأصلي إلى حزمة التوزيع المحمولة مع حماية بيانات العميل والإعدادات';

    /**
     * تنفيذ الأمر البرمجي
     */
    public function handle(): int
    {
        $sourcePath = base_path();
        $targetPath = $this->option('target');

        $this->info('====================================================');
        $this->info('  بدء مزامنة كود المشروع إلى مجلد التوزيع المحمول   ');
        $this->info('====================================================');

        // 1. التحقق من وجود مجلد التوزيع المستهدف
        if (!File::isDirectory($targetPath)) {
            $this->error("❌ المجلد المستهدف غير موجود: {$targetPath}");
            $this->line("يرجى التأكد من إنشاء مسار C:\\PandaDistribution\\app أولاً.");
            return self::FAILURE;
        }

        // 2. تنظيف كاش لارافيل بالكامل لضمان عدم نقل إعدادات الكاش القديمة
        $this->comment('1. تنظيف كاش لارافيل في المشروع الحالي...');
        Artisan::call('optimize:clear');
        $this->line(Artisan::output());

        // 3. تجهيز محرك البحث وتحديد الملفات والمجلدات المسموح بنقلها
        $this->comment('2. مسح ونقل الملفات البرمجية المحدثة...');

        $finder = new Finder();
        $finder->in($sourcePath)
            ->ignoreDotFiles(false)
            // استبعاد مجلدات التطوير والبيانات الحساسة
            ->exclude([
                'storage',
                'node_modules',
                '.git',
                'bootstrap/cache'
            ])
            // استبعاد ملفات الإعدادات والملفات المؤقتة
            ->notName('.env')
            ->notName('.env.example')
            ->notName('.gitignore');

        $copiedCount = 0;

        foreach ($finder as $item) {
            $relativePath = $item->getRelativePathname();
            $destination = $targetPath . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!File::isDirectory($destination)) {
                    File::makeDirectory($destination, 0755, true, true);
                }
            } else {
                $destDir = dirname($destination);
                if (!File::isDirectory($destDir)) {
                    File::makeDirectory($destDir, 0755, true, true);
                }
                File::copy($item->getRealPath(), $destination);
                $copiedCount++;
            }
        }

        // 4. تأمين مجلد bootstrap/cache نظيفاً في مجلد التوزيع
        $distCacheDir = $targetPath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache';
        if (!File::isDirectory($distCacheDir)) {
            File::makeDirectory($distCacheDir, 0755, true, true);
        }

        // حذف أي ملفات كاش متبقية داخل مجلد كاش التوزيع عدا .gitignore
        $cacheFiles = File::files($distCacheDir);
        foreach ($cacheFiles as $file) {
            if ($file->getFilename() !== '.gitignore') {
                File::delete($file->getRealPath());
            }
        }

        $this->info("✔ تم نسخ وتحديث {$copiedCount} ملفاً بنجاح.");
        $this->info('✔ تم عزل مجلد storage وملف .env وحماية قاعدة البيانات.');
        $this->info('====================================================');
        $this->info('✅ اكتملت المزامنة بنجاح! حزمة التوزيع جاهزة للـ Compile في Inno Setup.');
        $this->info('====================================================');

        return self::SUCCESS;
    }
}