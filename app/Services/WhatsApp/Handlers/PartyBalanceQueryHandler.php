<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Models\Customer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

class TopDebtorsQueryHandler implements QueryHandlerInterface
{
    /**
     * ربط مفاتيح الفروع بأسماء اتصالات قاعدة البيانات (في حال وجود قواعد بيانات منفصلة لكل فرع)
     */
    protected array $branchConnections = [
        'omd'    => 'branch_main',
        'madani' => 'branch_1',
        'port1'  => 'branch_2',
        'port2'  => 'branch_3',
    ];

    /**
     * الأسماء المترجمة للفروع للعرض على الواتساب
     */
    protected array $branchLabels = [
        'omd'    => 'أمدرمان',
        'madani' => 'مدني',
        'port1'  => 'بورتسودان 1',
        'port2'  => 'بورتسودان 2',
    ];

    public function getIntentName(): string
    {
        return 'top_debtors';
    }

    public function getDescription(): string
    {
        return 'عرض أعلى 10 عملاء مدينين بأعلى الأرصدة والمديونيات المستحقة للشركة عبر الفروع.';
    }

    public function handle(array $parsedIntent): string
    {
        $targetBranch = $parsedIntent['branch'] ?? 'all';

        // فحص ما إذا كان النظام يعمل باتصالات فروع متعددة أو اتصال افتراضي واحد
        $availableBranchConnections = $this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection();
        }

        return $this->handleMultiBranchConnections($targetBranch, $availableBranchConnections);
    }

    /**
     * التحقق من اتصالات الفروع المعرفة في config/database.php
     */
    protected function getAvailableBranchConnections(): array
    {
        $available = [];

        foreach ($this->branchConnections as $key => $connectionName) {
            if (Config::has("database.connections.{$connectionName}")) {
                $available[$key] = $connectionName;
            }
        }

        return $available;
    }

    /**
     * معالجة الاستعلام للعميل الذي يملك قاعدة بيانات واحدة افتراضية (Single Database Mode)
     */
    protected function handleSingleConnection(): string
    {
        $currency = config('app.currency', 'SDG');

        try {
            $customers = Customer::whereNull('deleted_at')
                ->where('current_balance', '>', 0)
                ->orderBy('current_balance', 'desc')
                ->take(10)
                ->get();

            if ($customers->isEmpty()) {
                return "✅ *ممتاز!* لا يوجد أي عملاء مدينين بمديونيات مسجلة حالياً.";
            }

            $output = "🚨 *قائمة أعلى 10 عملاء مدينين*\n";
            $output .= "-----------------------------------\n";

            $totalDebt = 0.0;
            foreach ($customers as $index => $customer) {
                $rank = $index + 1;
                $name = $customer->name;
                $balance = (float) $customer->current_balance;
                $totalDebt += $balance;

                $output .= "{$rank}️⃣ *{$name}*\n";
                $output .= "   └ المديونية: *" . number_format($balance, 0) . " {$currency}*\n\n";
            }

            $output .= "-----------------------------------\n";
            $output .= "💰 *إجمالي مديونيات هذه القائمة*: *" . number_format($totalDebt, 0) . " {$currency}*";

            return trim($output);

        } catch (Throwable $e) {
            Log::error("TopDebtorsQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج قائمة العملاء المدينين حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام للعملاء الذين يمتلكون قواعد بيانات مستقلة لكل فرع (Multi-Database Mode)
     */
    protected function handleMultiBranchConnections(string $targetBranch, array $availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');

        $connectionsToQuery = [];
        if ($targetBranch === 'all' || !isset($availableBranchConnections[$targetBranch])) {
            $connectionsToQuery = $availableBranchConnections;
        } else {
            $connectionsToQuery[$targetBranch] = $availableBranchConnections[$targetBranch];
        }

        $debtorsList = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $customers = Customer::on($connectionName)
                    ->whereNull('deleted_at')
                    ->where('current_balance', '>', 0)
                    ->orderBy('current_balance', 'desc')
                    ->take(10)
                    ->get();

                foreach ($customers as $customer) {
                    $key = $customer->phone ?: $customer->name;
                    $branchLabel = $this->branchLabels[$branchKey] ?? "فرع ({$branchKey})";

                    if (!isset($debtorsList[$key])) {
                        $debtorsList[$key] = [
                            'name'    => $customer->name,
                            'phone'   => $customer->phone ?? 'غير مسجل',
                            'balance' => (float) $customer->current_balance,
                            'branch'  => $branchLabel,
                        ];
                    } else {
                        // تجميع الأرصدة في حال وجود العميل في أكثر من فرع
                        $debtorsList[$key]['balance'] += (float) $customer->current_balance;
                    }
                }
            } catch (Throwable $e) {
                Log::error("TopDebtorsQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (empty($debtorsList)) {
            return "✅ *ممتاز!* لا يوجد أي عملاء مدينين بمديونيات مسجلة حالياً.";
        }

        // فرز القائمة المجمعة تنازلياً وأخذ أعلى 10 فقط
        usort($debtorsList, fn($a, $b) => $b['balance'] <=> $a['balance']);
        $top10 = array_slice($debtorsList, 0, 10);

        return $this->formatMultiBranchWhatsAppOutput($top10, $targetBranch, $currency);
    }

    /**
     * تنسيق مخرجات الواتساب في بيئة الفروع المتعددة
     */
    protected function formatMultiBranchWhatsAppOutput(array $debtors, string $targetBranch, string $currency): string
    {
        $branchTitle = ($targetBranch !== 'all' && isset($this->branchLabels[$targetBranch]))
            ? "({$this->branchLabels[$targetBranch]})"
            : "(جميع الفروع)";

        $output = "🚨 *قائمة أعلى 10 عملاء مدينين {$branchTitle}*\n";
        $output .= "-----------------------------------\n";

        $totalDebt = 0.0;
        foreach ($debtors as $index => $debtor) {
            $rank = $index + 1;
            $name = $debtor['name'];
            $balance = number_format($debtor['balance'], 0);
            $totalDebt += $debtor['balance'];

            $output .= "{$rank}️⃣ *{$name}*\n";
            $output .= "   ├ المديونية: *{$balance} {$currency}*\n";
            $output .= "   └ الفرع: {$debtor['branch']}\n\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "💰 *إجمالي مديونيات هذه القائمة*: *" . number_format($totalDebt, 0) . " {$currency}*";

        return trim($output);
    }
}