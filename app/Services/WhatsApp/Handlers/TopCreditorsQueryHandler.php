<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Throwable;

class TopCreditorsQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'top_creditors';
    }

    public function getDescription(): string
    {
        return 'عرض أعلى 10 موردين لهم مستحقات أو ديون مالية على الشركة مرتبة من الأعلى إلى الأقل.';
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
            $suppliers = Supplier::whereNull('deleted_at')
                ->where('current_balance', '>', 0)
                ->orderBy('current_balance', 'desc')
                ->take(10)
                ->get();

            if ($suppliers->isEmpty()) {
                return "✅ *ممتاز!* لا توجد أي مستحقات أو ديون مسجلة للموردين حالياً.";
            }

            $output = "📋 *تقرير مستحقات وديون الموردين (أعلى 10 مستحقين)*\n";
            $output .= "-----------------------------------\n";

            $totalDebt = 0.0;
            foreach ($suppliers as $index => $supplier) {
                $rank = $index + 1;
                $name = $supplier->name;
                $phone = $supplier->phone ? " ({$supplier->phone})" : "";
                $balance = (float) $supplier->current_balance;
                $totalDebt += $balance;

                $output .= "{$rank}️⃣ *{$name}*{$phone}\n";
                $output .= "   └ المستحق له: *" . number_format($balance, 0) . " {$currency}*\n\n";
            }

            $output .= "-----------------------------------\n";
            $output .= "💰 *إجمالي مستحقات الموردين الموضحة*: *" . number_format($totalDebt, 0) . " {$currency}*";

            return trim($output);

        } catch (Throwable $e) {
            Log::error("TopCreditorsQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير مستحقات الموردين حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام في حال تفعيل الفروع اختيارياً
     */
    protected function handleMultiBranchConnections(string $targetBranch, array $availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');
        $connectionsToQuery = $this->resolveConnectionsToQuery($targetBranch, $availableBranchConnections);
        $creditorsList = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $suppliers = Supplier::on($connectionName)
                    ->whereNull('deleted_at')
                    ->where('current_balance', '>', 0)
                    ->orderBy('current_balance', 'desc')
                    ->take(10)
                    ->get();

                $branchLabel = $this->getBranchLabel($branchKey);

                foreach ($suppliers as $supplier) {
                    $key = $supplier->phone ? $supplier->phone : $supplier->name;

                    if (!isset($creditorsList[$key])) {
                        $creditorsList[$key] = [
                            'name'    => $supplier->name,
                            'phone'   => $supplier->phone ? $supplier->phone : '',
                            'balance' => (float) $supplier->current_balance,
                            'branch'  => $branchLabel,
                        ];
                    } else {
                        $creditorsList[$key]['balance'] += (float) $supplier->current_balance;
                    }
                }
            } catch (Throwable $e) {
                Log::error("TopCreditorsQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (empty($creditorsList)) {
            return "✅ *ممتاز!* لا توجد أي مستحقات أو ديون مسجلة للموردين حالياً.";
        }

        usort($creditorsList, fn($a, $b) => $b['balance'] <=> $a['balance']);
        $top10 = array_slice($creditorsList, 0, 10);

        $output = "📋 *تقرير مستحقات وديون الموردين (أعلى 10 مستحقين)*\n";
        $output .= "-----------------------------------\n";

        $totalDebt = 0.0;
        foreach ($top10 as $index => $creditor) {
            $rank = $index + 1;
            $name = $creditor['name'];
            $phone = !empty($creditor['phone']) ? " ({$creditor['phone']})" : "";
            $balance = number_format($creditor['balance'], 0);
            $totalDebt += $creditor['balance'];

            $output .= "{$rank}️⃣ *{$name}*{$phone}\n";
            $output .= "   ├ المستحق له: *{$balance} {$currency}*\n";
            $output .= "   └ جهة القيد: {$creditor['branch']}\n\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "💰 *إجمالي مستحقات الموردين الموضحة*: *" . number_format($totalDebt, 0) . " {$currency}*";

        return trim($output);
    }
}