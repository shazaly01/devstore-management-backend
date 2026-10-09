<?php

namespace App\Services\WhatsApp;

class ReportManagerService
{
    protected QueryHandlerRegistry $registry;

    /**
     * دعم الإنشاء المباشر new ReportManagerService() أو الـ Injection
     */
    public function __construct(?QueryHandlerRegistry $registry = null)
    {
        $this->registry = $registry ?? app(QueryHandlerRegistry::class);
    }

    /**
     * معالجة النية وإصدار الرد مع الحماية الكاملة من قيم null
     */
    public function generateReport(?array $parsedIntent): string
    {
        // في حال فشل الذكاء الاصطناعي أو إرجاع null، توجيه الطلب تلقائياً لنية unknown لعرض دليل المساعدة
        $safeIntent = $parsedIntent ?? ['intent' => 'unknown'];

        return $this->registry->handle($safeIntent);
    }
}