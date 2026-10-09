<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntentParsingService
{
    /**
     * المسار السريع للأنماط المباشرة الشائعة بألفاظ السوق المباشرة (0 توكن)
     */
    protected array $quickPatterns = [
        // 1. دليل الأوامر والمساعدة
        '/^(التعليمات|تعليمات|مساعدة|الاوامر|الأوامر|القائمة|منيو|دليل|اوامر|أوامر|help|menu|\?|؟)$/ui' => ['intent' => 'help_menu', 'branch' => 'all'],

        // 2. المبيعات
        '/^(مبيعات اليوم|دخل اليوم|مبيعات لليوم)$/u'           => ['intent' => 'sales_report', 'period' => 'today', 'branch' => 'all'],
        '/^(مبيعات امس|مبيعات أمس|دخل امس)$/u'               => ['intent' => 'sales_report', 'period' => 'yesterday', 'branch' => 'all'],
        '/^(مبيعات هذا الاسبوع|مبيعات الاسبوع)$/u'           => ['intent' => 'sales_report', 'period' => 'this_week', 'branch' => 'all'],
        '/^(مبيعات الاسبوع الماضي|مبيعات الاسبوع الفات)$/u'  => ['intent' => 'sales_report', 'period' => 'last_week', 'branch' => 'all'],
        '/^(مبيعات هذا الشهر|مبيعات الشهر)$/u'               => ['intent' => 'sales_report', 'period' => 'this_month', 'branch' => 'all'],
        '/^(مبيعات الشهر الماضي|مبيعات الشهر الفات)$/u'      => ['intent' => 'sales_report', 'period' => 'last_month', 'branch' => 'all'],

        // 3. السيولة والخزائن والبنوك
        '/^(السيولة|موقف السيولة|الكاش|رصيد الخزائن|رصيد الخزينة|البنوك|رصيد البنك)$/u' => ['intent' => 'liquidity_summary', 'branch' => 'all'],

        // 4. المصروفات
        '/^(مصروفات اليوم|صرفيات اليوم)$/u'                   => ['intent' => 'expenses_summary', 'period' => 'today', 'branch' => 'all'],
        '/^(مصروفات امس|مصروفات أمس|صرفيات امس)$/u'           => ['intent' => 'expenses_summary', 'period' => 'yesterday', 'branch' => 'all'],
        '/^(مصروفات هذا الاسبوع|صرفيات هذا الاسبوع)$/u'       => ['intent' => 'expenses_summary', 'period' => 'this_week', 'branch' => 'all'],
        '/^(مصروفات هذا الشهر|صرفيات هذا الشهر)$/u'           => ['intent' => 'expenses_summary', 'period' => 'this_month', 'branch' => 'all'],
        '/^(مصروفات الشهر الماضي|صرفيات الشهر الفات)$/u'      => ['intent' => 'expenses_summary', 'period' => 'last_month', 'branch' => 'all'],

        // 5. المخزون والنواقص
        '/^(النواقص|تقرير النواقص|الاصناف المنتهية)$/u'        => ['intent' => 'low_stock', 'branch' => 'all'],

        // 6. ديون وأرصدة العملاء
        '/^(ديون العملاء|ارصدة العملاء|أرصدة العملاء|حسابات العملاء|كبار العملاء|كبار المدينين)$/u' => ['intent' => 'top_debtors', 'branch' => 'all'],

        // 7. مستحقات وديون الموردين
        '/^(مستحقات الموردين|ديون الموردين|ارصدة الموردين|أرصدة الموردين|حسابات الموردين|كبار الموردين)$/u' => ['intent' => 'top_creditors', 'branch' => 'all'],
    ];

    /**
     * تحليل نية النص الوارد من الواتساب
     */
    public function parseIntent(string $userMessage, string $phoneNumber): ?array
    {
        $cleanedMessage = $this->sanitizeInput($userMessage);

        if (empty($cleanedMessage)) {
            return null;
        }

        // 1. فحص المسار السريع للأوامر المباشرة (0 توكن واستجابة فورية)
        $quickResult = $this->matchQuickPattern($cleanedMessage);
        if ($quickResult !== null) {
            return $quickResult;
        }

        // 2. فحص التحيات والمجاملات وتوجيهها لدليل التعليمات (0 توكن)
        if ($this->isGreeting($cleanedMessage)) {
            return [
                'intent'     => 'help_menu',
                'branch'     => 'all',
                'period'     => null,
                'date'       => null,
                'item_name'  => null,
                'party_name' => null,
                'party_type' => 'all',
            ];
        }

        // 3. الاستعانة بالذكاء الاصطناعي للاستعلامات المعقدة والبحث بالأسماء
        return $this->executeAiInference($cleanedMessage);
    }

    /**
     * فحص التحيات والمجاملات العامة
     */
    protected function isGreeting(string $text): bool
    {
        return (bool) preg_match('/^(سلام|السلام عليكم|مرحبا|هلا|صباح الخير|مساء الخير|شكرا|تسلم|منو معاي|من انت)$/u', $text);
    }

    /**
     * مطابقة النص بالمسار السريع
     */
    protected function matchQuickPattern(string $text): ?array
    {
        foreach ($this->quickPatterns as $pattern => $result) {
            if (preg_match($pattern, $text)) {
                return array_merge([
                    'period'     => null,
                    'date'       => null,
                    'item_name'  => null,
                    'party_name' => null,
                    'party_type' => 'all',
                ], $result);
            }
        }

        return null;
    }

    /**
     * الاتصال بـ DeepSeek API مع استغلال الـ Prompt Caching
     */
    protected function executeAiInference(string $message): ?array
    {
        $apiKey  = config('services.deepseek.key');
        $baseUrl = config('services.deepseek.url', 'https://api.deepseek.com');

        $endpoint = str_contains($baseUrl, 'chat/completions')
            ? $baseUrl
            : rtrim($baseUrl, '/') . '/chat/completions';

        $today = now()->format('Y-m-d');
        $dayName = now()->locale('ar')->isoFormat('dddd');

        $userPayload = "[تاريخ اليوم: {$today} ({$dayName})]\nاستعلام: {$message}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(12)->post($endpoint, [
                'model'           => 'deepseek-chat',
                'messages'        => [
                    ['role' => 'system', 'content' => $this->getStaticSystemPrompt()],
                    ['role' => 'user', 'content' => $userPayload],
                ],
                'temperature'     => 0.0,
                'max_tokens'      => 150,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                return json_decode($content, true);
            }

            Log::error('❌ [WA-IntentParsing] فشل استجابة DeepSeek API', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;

        } catch (Throwable $e) {
            Log::error('❌ [WA-IntentParsing] استثناء أثناء الاتصال بـ DeepSeek', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * موجه نظام ثابت ومكثف 100% لتحقيق أقصى نسبة Cache Hit وتوفير التوكن
     */
    protected function getStaticSystemPrompt(): string
    {
        return <<<PROMPT
Parse ERP queries into JSON only:
{
  "intent": "sales_report|liquidity_summary|expenses_summary|top_creditors|top_debtors|party_balance|item_stock|low_stock|latest_invoice|help_menu|unknown",
  "branch": "omd|madani|port1|port2|all",
  "period": "today|yesterday|this_week|last_week|this_month|last_month|null",
  "date": "YYYY-MM-DD|null",
  "item_name": "clean string|null",
  "party_name": "clean string|null",
  "party_type": "customer|supplier|all"
}

Branch aliases:
- omd: المركز الرئيسي, امدرمان
- madani: مدني, الجزيرة
- port1: بورتسودان 1, المريخ, الميناء
- port2: بورتسودان 2, السوق
- all: default if branch not specified

Rules:
1. Strip query words (رصيد, سعر, حساب, كم, كشف, توفر) from item_name/party_name.
2. In sales/expenses: set period (today, yesterday, this_week, last_week, this_month, last_month). If relative periodic, date=null.
3. If explicit date given, parse to YYYY-MM-DD.
4. "ديون العملاء", "ارصدة العملاء", "كبار العملاء" -> intent: "top_debtors".
5. "مستحقات الموردين", "ديون الموردين", "ارصدة الموردين" -> intent: "top_creditors".
6. If user asks for instructions, commands or help, return intent=help_menu.
7. Return JSON only. No explanations.
PROMPT;
    }

    /**
     * تنظيف وضبط مدخلات الرسالة
     */
    protected function sanitizeInput(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim(mb_substr((string) $text, 0, 150));
    }
}