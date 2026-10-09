<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueryHandlerRegistry
{
    /**
     * قائمة معالجات الاستعلامات المسجلة في النظام
     *
     * @var array<string, QueryHandlerInterface>
     */
    protected array $handlers = [];

    /**
     * تسجيل معالج استعلام جديد
     */
    public function register(QueryHandlerInterface $handler): void
    {
        $this->handlers[$handler->getIntentName()] =$handler;
    }

    /**
     * تنفيذ الاستعلام المناسب وتسجيل خطوات التنفيذ
     */
    public function handle(array $parsedIntent): string
    {
        $intent =$parsedIntent['intent'] ?? 'unknown';

        Log::info(" [WA-Registry] استلام نية لمعالجتها", [
            'intent'  => $intent,
            'payload' => $parsedIntent,
        ]);

        if (isset($this->handlers[$intent])) {
            try {
                Log::info(" [WA-Registry] توجيه النية للـ Handler المسجل: " . get_class($this->handlers[$intent]));

                $response =$this->handlers[$intent]->handle($parsedIntent);

                Log::info(" [WA-Registry] تم توليد الرد بنجاح", [
                    'intent'   => $intent,
                    'response' => $response,
                ]);

                return $response;

            } catch (Throwable $e) {
                Log::error("❌ [WA-Registry] خطأ أثناء تنفيذ الـ Handler [{$intent}]", [
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                    'payload' => $parsedIntent,
                ]);

                return "⚠️ تعذر استكمال الاستعلام حالياً بسبب خطأ غير متوقع. يرجى المحاولة لاحقاً.";
            }
        }

        Log::warning("⚠️ [WA-Registry] لم يتم العثور على Handler للنية: {$intent}");

        return $this->getFallbackResponse();
    }

    /**
     * جلب جميع المعالجات المسجلة
     */
    public function getRegisteredHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * الرد التوضيحي الافتراضي مع عرض كافة التقارير التشغيلية والتنفيذية المتاحة
     */
    protected function getFallbackResponse(): string
    {
        return "🤖 *عذراً، لم أستطع فهم نوع الاستعلام المطلوب بدقة.*\n\n"
             . "💡 *إليك أهم التقارير والخدمات المتاحة وكيفية طلبها:* \n\n"
             . "1️⃣ *المبيعات والإيرادات:*\n"
             . "   • `مبيعات اليوم` - `مبيعات أمس`\n"
             . "   • `مبيعات هذا الأسبوع` - `مبيعات هذا الشهر`\n"
             . "   • `مبيعات فرع مدني`\n\n"
             . "2️⃣ *الموقف المالي والسيولة النقدية:*\n"
             . "   • `موقف السيولة` أو `كم عندنا كاش؟`\n"
             . "   • `رصيد الخزائن` أو `أرصدة البنوك`\n\n"
             . "3️⃣ *المصروفات والنفقات:*\n"
             . "   • `مصروفات اليوم` أو `مصروفات أمس`\n"
             . "   • `صرفيات هذا الأسبوع` أو `مصروفات هذا الشهر`\n\n"
             . "4️⃣ *كشف الحسابات والأرصدة:*\n"
             . "   • `رصيد العميل أحمد محمد`\n"
             . "   • `رصيد المورد شركة الأمل`\n"
             . "   • `آخر فاتورة لعميل طارق`\n\n"
             . "5️⃣ *الديون والالتزامات:*\n"
             . "   • `أعلى 10 عملاء مدينين` (الديون المستحقة لنا)\n"
             . "   • `كبار الموردين الدائنين` (الالتزامات الواجب سدادها)\n\n"
             . "6️⃣ *المخزون والأسعار والنواقص:*\n"
             . "   • `رصيد وسعر بنر 130`\n"
             . "   • `تقرير النواقص` أو `نواقص فرع أمدرمان`\n\n"
             . "✍️ *جرب كتابة سؤالك بصيغة مباشرة وسأجيبك فوراً.*";
    }
}