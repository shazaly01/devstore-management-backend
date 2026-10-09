<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use App\Models\Treasury;
use App\Models\Bank;
use Illuminate\Support\Facades\Log;
use Throwable;

class LiquidityQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'liquidity_summary';
    }

    public function getDescription(): string
    {
        return 'استعلام تنفيذي عن الموقف المالي الفوري والسيولة النقدية المتاحة وأرصدة الخزائن والبنوك للشركة والفروع.';
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
            $data = $this->fetchLiquidityData(null);

            return $this->formatWhatsAppOutput($data, 'المركز الرئيسي', $currency, []);

        } catch (Throwable $e) {
            Log::error("LiquidityQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير السيولة النقدية حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة مع رصد الانقطاعات
     */
    protected function handleMultiBranchConnections(string $targetBranch, array $availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');
        $connectionsToQuery = $this->resolveConnectionsToQuery($targetBranch, $availableBranchConnections);

        $aggregatedData = [
            'treasuries'      => [],
            'banks'           => [],
            'total_treasury'  => 0.0,
            'total_bank'      => 0.0,
            'total_liquidity' => 0.0,
        ];

        $failedBranches = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            $branchLabel = $this->getBranchLabel($branchKey);

            try {
                $branchData = $this->fetchLiquidityData($connectionName);

                foreach ($branchData['treasuries'] as $treasury) {
                    $treasury['name'] .= " ({$branchLabel})";
                    $aggregatedData['treasuries'][] = $treasury;
                }

                foreach ($branchData['banks'] as $bank) {
                    $bank['name'] .= " ({$branchLabel})";
                    $aggregatedData['banks'][] = $bank;
                }

                $aggregatedData['total_treasury'] += $branchData['total_treasury'];
                $aggregatedData['total_bank'] += $branchData['total_bank'];
                $aggregatedData['total_liquidity'] += $branchData['total_liquidity'];

            } catch (Throwable $e) {
                Log::error("LiquidityQueryHandler Error [{$branchKey}]: " . $e->getMessage());
                $failedBranches[] = $branchLabel;
            }
        }

        $branchTitle = ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch]))
            ? $this->branchLabels[$targetBranch]
            : 'كافة الفروع';

        return $this->formatWhatsAppOutput($aggregatedData, $branchTitle, $currency, $failedBranches);
    }

    /**
     * استعلام الأرصدة المادية للخزائن والبنوك
     */
    protected function fetchLiquidityData(?string $connection): array
    {
        $treasuryQuery = !empty($connection) ? Treasury::on($connection) : Treasury::query();
        $bankQuery = !empty($connection) ? Bank::on($connection) : Bank::query();

        $treasuries = $treasuryQuery->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();

        $banks = $bankQuery->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();

        $treasuryList = [];
        $totalTreasury = 0.0;
        foreach ($treasuries as $t) {
            $bal = (float) $t->current_balance;
            $treasuryList[] = [
                'name'    => $t->name,
                'balance' => $bal,
            ];
            $totalTreasury += $bal;
        }

        $bankList = [];
        $totalBank = 0.0;
        foreach ($banks as $b) {
            $bal = (float) $b->current_balance;
            $bankList[] = [
                'name'    => $b->name,
                'balance' => $bal,
            ];
            $totalBank += $bal;
        }

        return [
            'treasuries'      => $treasuryList,
            'banks'           => $bankList,
            'total_treasury'  => $totalTreasury,
            'total_bank'      => $totalBank,
            'total_liquidity' => $totalTreasury + $totalBank,
        ];
    }

    /**
     * تنسيق تقرير السيولة النقدية مع التنبيه عن الفروع المتعثرة
     */
    protected function formatWhatsAppOutput(array $data, string $branchTitle, string $currency, array $failedBranches): string
    {
        $output = "💼 *تقرير موقف السيولة النقدية المتاحة*\n";
        $output .= "🏢 *النطاق*: {$branchTitle}\n";
        $output .= "-----------------------------------\n";

        $output .= "💵 *الخزائن النقدية (الكاش)*: *" . number_format($data['total_treasury'], 0) . " {$currency}*\n";
        if (!empty($data['treasuries'])) {
            foreach ($data['treasuries'] as $t) {
                $output .= "  ├ {$t['name']}: " . number_format($t['balance'], 0) . " {$currency}\n";
            }
        } else {
            $output .= "  └ لا توجد خزائن نشطة.\n";
        }

        $output .= "\n💳 *الحسابات البنكية*: *" . number_format($data['total_bank'], 0) . " {$currency}*\n";
        if (!empty($data['banks'])) {
            foreach ($data['banks'] as $b) {
                $output .= "  ├ {$b['name']}: " . number_format($b['balance'], 0) . " {$currency}\n";
            }
        } else {
            $output .= "  └ لا توجد حسابات بنكية نشطة.\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "🏦 *صافي السيولة النقدية الكلية*: *" . number_format($data['total_liquidity'], 0) . " {$currency}*\n";

        // إشعار شفافية فوري للإدارة إذا تعذر جلب رصيد فرع معين
        if (!empty($failedBranches)) {
            $output .= "-----------------------------------\n";
            $output .= "⚠️ *تنبيه*: تعذر الاتصال بـ (" . implode('، ', $failedBranches) . ")، والأرقام أعلاه لا تشملها.\n";
        }

        $output .= "⏱️ _تم استخراج البيانات لحظياً من النظام._";

        return trim($output);
    }
}