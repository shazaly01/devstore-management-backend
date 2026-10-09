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
        return 'عرض أعلى 10 عملاء عليهم مديونيات أو أرصدة مستحقة للشركة مرتبة من الأعلى إلى الأقل.';
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
     * معالجة الاستعلام المباشر للنظام العادي (بدون أي ذكر للفروع)
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
                return "✅ *ممتاز!* لا توجد أي مديونيات مسجلة على العملاء حالياً.";
            }

            $output = "👥 *تقرير ديون وأرصدة العملاء (أعلى 10 مستحقين)*\n";
            $output .= "-----------------------------------\n";

            $totalDebt = 0.0;
            foreach ($customers as $index => $customer) {
                $rank = $index + 1;
                $name = $customer->name;
                $phone = $customer->phone ? " ({$customer->phone})" : "";
                $balance = (float) $customer->current_balance;
                $totalDebt += $balance;

                $output .= "{$rank}️⃣ *{$name}*{$phone}\n";
                $output .= "   └ المبلغ المستحق: *" . number_format($balance, 0) . " {$currency}*\n\n";
            }

            $output .= "-----------------------------------\n";
            $output .= "💰 *إجمالي ديون العملاء الموضحة*: *" . number_format($totalDebt, 0) . " {$currency}*";

            return trim($output);

        } catch (Throwable $e) {
            Log::error("TopDebtorsQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير ديون العملاء حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام في حال تفعيل الفروع اختيارياً
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
                            'phone'   => $customer->phone ? $customer->phone : '',
                            'balance' => (float) $customer->current_balance,
                            'branch'  => $branchLabel,
                        ];
                    } else {
                        $debtorsList[$key]['balance'] += (float) $customer->current_balance;
                    }
                }
            } catch (Throwable $e) {
                Log::error("TopDebtorsQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (empty($debtorsList)) {
            return "✅ *ممتاز!* لا توجد أي مديونيات مسجلة على العملاء حالياً.";
        }

        usort($debtorsList, fn($a, $b) => $b['balance'] <=> $a['balance']);
        $top10 = array_slice($debtorsList, 0, 10);

        $output = "👥 *تقرير ديون وأرصدة العملاء (أعلى 10 مستحقين)*\n";
        $output .= "-----------------------------------\n";

        $totalDebt = 0.0;
        foreach ($top10 as $index => $debtor) {
            $rank = $index + 1;
            $name = $debtor['name'];
            $phone = !empty($debtor['phone']) ? " ({$debtor['phone']})" : "";
            $balance = number_format($debtor['balance'], 0);
            $totalDebt += $debtor['balance'];

            $output .= "{$rank}️⃣ *{$name}*{$phone}\n";
            $output .= "   ├ المبلغ المستحق: *{$balance} {$currency}*\n";
            $output .= "   └ جهة القيد: {$debtor['branch']}\n\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "💰 *إجمالي ديون العملاء الموضحة*: *" . number_format($totalDebt, 0) . " {$currency}*";

        return trim($output);
    }
}