<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Throwable;

class TopDebtorsQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'top_debtors';
    }

    public function getDescription(): string
    {
        return 'عرض أعلى 10 عملاء مدينين بأعلى المديونيات والمبالغ المستحقة للشركة عبر الفروع.';
    }

    public function handle(array $parsedIntent): string
    {
        $targetBranch = $parsedIntent['branch'] ?? 'all';
        $availableBranchConnections = $this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection();
        }

        return $this->handleMultiBranchConnections($targetBranch, $availableBranchConnections);
    }

    /**
     * معالجة الاستعلام لنمط قاعدة البيانات الواحدة
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
                return "✅ *ممتاز!* لا توجد أي مديونيات متأخرة على العملاء حالياً.";
            }

            $output = "📋 *قائمة أعلى 10 عملاء مدينين (الديون المستحقة)*\n";
            $output .= "🏢 *الفرع*: المركز الرئيسي\n";
            $output .= "-----------------------------------\n";

            $totalDebt = 0.0;
            foreach ($customers as $index => $customer) {
                $rank = $index + 1;
                $name = $customer->name;
                $phone = $customer->phone ? " ({$customer->phone})" : "";
                $balance = (float) $customer->current_balance;
                $totalDebt += $balance;

                $output .= "{$rank}️⃣ *{$name}*{$phone}\n";
                $output .= "   └ المديونية: *" . number_format($balance, 0) . " {$currency}*\n\n";
            }

            $output .= "-----------------------------------\n";
            $output .= "💰 *إجمالي مديونيات هذه القائمة*: *" . number_format($totalDebt, 0) . " {$currency}*";

            return trim($output);

        } catch (Throwable $e) {
            Log::error("TopDebtorsQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج قائمة مديونيات العملاء حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة
     */
    protected function handleMultiBranchConnections(string $targetBranch, array $availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');
        $connectionsToQuery = $this->resolveConnectionsToQuery($targetBranch, $availableBranchConnections);
        $debtorsList = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $customers = Customer::on($connectionName)
                    ->whereNull('deleted_at')
                    ->where('current_balance', '>', 0)
                    ->orderBy('current_balance', 'desc')
                    ->take(10)
                    ->get();

                $branchLabel = $this->getBranchLabel($branchKey);

                foreach ($customers as $customer) {
                    $key = $customer->phone ? $customer->phone : $customer->name;

                    if (!isset($debtorsList[$key])) {
                        $debtorsList[$key] = [
                            'name'    => $customer->name,
                            'phone'   => $customer->phone ? $customer->phone : 'غير مسجل',
                            'balance' => (float) $customer->current_balance,
                            'branch'  => $branchLabel,
                        ];
                    } else {
                        // تجميع الأرصدة في حال تكرار العميل عبر الفروع
                        $debtorsList[$key]['balance'] += (float) $customer->current_balance;
                    }
                }
            } catch (Throwable $e) {
                Log::error("TopDebtorsQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (empty($debtorsList)) {
            return "✅ *ممتاز!* لا توجد أي مديونيات متأخرة على العملاء حالياً.";
        }

        // الترتيب تنازلياً وأخذ أعلى 10 عملاء
        usort($debtorsList, fn($a, $b) => $b['balance'] <=> $a['balance']);
        $top10 = array_slice($debtorsList, 0, 10);

        return $this->formatMultiBranchWhatsAppOutput($top10, $targetBranch, $currency);
    }

    /**
     * تنسيق مخرجات الواتساب في بيئة الفروع المتعددة
     */
    protected function formatMultiBranchWhatsAppOutput(array $debtors, string $targetBranch, string $currency): string
    {
        $branchTitle = ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch]))
            ? "({$this->branchLabels[$targetBranch]})"
            : "(كافة الفروع)";

        $output = "📋 *قائمة أعلى 10 عملاء مدينين {$branchTitle}*\n";
        $output .= "-----------------------------------\n";

        $totalDebt = 0.0;
        foreach ($debtors as $index => $debtor) {
            $rank = $index + 1;
            $name = $debtor['name'];
            $phone = ($debtor['phone'] !== 'غير مسجل') ? " ({$debtor['phone']})" : "";
            $balance = number_format($debtor['balance'], 0);
            $totalDebt += $debtor['balance'];

            $output .= "{$rank}️⃣ *{$name}*{$phone}\n";
            $output .= "   ├ المديونية: *{$balance} {$currency}*\n";
            $output .= "   └ الفرع: {$debtor['branch']}\n\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "💰 *إجمالي مديونيات هذه القائمة*: *" . number_format($totalDebt, 0) . " {$currency}*";

        return trim($output);
    }
}