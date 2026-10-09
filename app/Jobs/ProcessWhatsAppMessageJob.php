<?php

namespace App\Jobs;

use App\Services\WhatsApp\IntentParsingService;
use App\Services\WhatsApp\ReportManagerService;
use App\Services\WhatsApp\WhatsAppResponseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * تحديد المحاولة بمرة واحدة فقط لمنع تكرار استهلاك التوكن ومنع إرسال رسائل مكررة للمدير
     *
     * @var int
     */
    public $tries = 1;

    /**
     * مهلة تنفيذ الوظيفة بالثواني
     *
     * @var int
     */
    public $timeout = 40;

    /**
     * إنشاء كائن المهمة
     *
     * @param string $senderPhone
     * @param string $messageText
     * @param string $messageId
     */
    public function __construct(
        public string $senderPhone,
        public string $messageText,
        public string $messageId
    ) {}

    /**
     * تنفيذ معالجة الرسالة والاستعلام
     *
     * @param IntentParsingService $intentParser
     * @param ReportManagerService $reportManager
     * @param WhatsAppResponseService $whatsappResponse
     * @return void
     */
    public function handle(
        IntentParsingService $intentParser,
        ReportManagerService $reportManager,
        WhatsAppResponseService $whatsappResponse
    ): void {
        try {
            // 1. تحليل النية واستخراج المقاصد (عبر المسار السريع بـ 0 توكن أو DeepSeek)
            $parsedIntent = $intentParser->parseIntent($this->messageText, $this->senderPhone);

            // 2. ضمان وجود مصفوفة نية صالحة وتمرير سياق رقم المرسل إليها
            if (!$parsedIntent) {
                $parsedIntent = ['intent' => 'unknown'];
            }
            $parsedIntent['sender_phone'] = $this->senderPhone;

            // 3. استعلام قاعدة البيانات وتوليد التقرير المطلوب عبر المعالج المسجل
            $reportResult = $reportManager->generateReport($parsedIntent);

            // 4. إرسال التقرير النهائي إلى هاتف المدير عبر الواتساب
            $whatsappResponse->sendTextMessage($this->senderPhone, $reportResult);

        } catch (Throwable $e) {
            Log::error('❌ [WA-Queue] فشل معالجة رسالة الواتساب', [
                'message_id'   => $this->messageId,
                'sender_phone' => $this->senderPhone,
                'message_text' => $this->messageText,
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);

            // إرسال تنبيه آمن ومباشر للمدير
            $whatsappResponse->sendTextMessage(
                $this->senderPhone,
                "⚠️ عذراً، تعذر إكمال الاستعلام حالياً بسبب عطل مؤقت في معالجة البيانات. يرجى المحاولة لاحقاً."
            );
        }
    }
}