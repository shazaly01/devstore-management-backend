<?php

namespace App\Providers;

use App\Services\LicenseService;
use Illuminate\Support\ServiceProvider;

class LicenseServiceProvider extends ServiceProvider
{
    /**
     * تسجيل خدمات الترخيص وتحميل النواة في الذاكرة.
     */
    public function register(): void
    {
        $this->loadSecurityCore();

        $this->app->singleton(LicenseService::class, function ($app) {
            return new LicenseService();
        });
    }

    /**
     * تحميل النواة بأمان سواء في بيئة الإنتاج أو التطوير المحلي.
     */
    protected function loadSecurityCore(): void
    {
        if (class_exists(\Panda\Core\PandaSecurityEngine::class)) {
            return;
        }

        $pharPath = base_path('panda_core.phar');
        $pubKeyPath = base_path('panda_core.phar.pubkey');

        // تحميل ملف الحزمة فقط إذا وُجد مع مفتاحه العام
        if (file_exists($pharPath) && file_exists($pubKeyPath)) {
            require_once $pharPath;
            return;
        }

        // مسار التطوير المباشر داخل app/core_src
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

    public function boot(): void
    {
        //
    }
}