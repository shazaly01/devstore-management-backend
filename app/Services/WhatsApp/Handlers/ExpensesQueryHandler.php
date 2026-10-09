<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;

class ExpensesQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'expenses_summary';
    }

    public function getDescription(): string
    {
        return 'استعلام عن ملخص المصروفات والنفقات لليوم أو أمس أو هذا الأسبوع أو الأسبوع الماضي أو هذا الشهر أو الشهر الماضي مصنفة حسب بنود الصرف والخزائن.';
    }

    public function handle(array $parsedIntent): string
    {
        $targetBranch = $parsedIntent['branch'] ?? 'all';
        $periodConfig = $this->resolveDateRange($parsedIntent);
        $availableBranchConnections = $this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection($periodConfig);
        }

        return $this->handleMultiBranchConnections($periodConfig, $targetBranch, $availableBranchConnections);
    }

    /**
     * معالجة وتحليل النطاق الزمني للمصروفات مع دعم الفترات السابقة والحالية
     */
    protected function resolveDateRange(array $parsedIntent): array
    {
        $period = $parsedIntent['period'] ?? null;
        $rawDate = $parsedIntent['date'] ?? null;

        if ($period === 'last_month') {
            $lastMonth = now()->subMonth();
            return [
                'start_date' => $lastMonth->startOfMonth()->format('Y-m-d'),
                'end_date'   => $lastMonth->endOfMonth()->format('Y-m-d'),
                'title'      => 'الشهر الماضي (' . $lastMonth->locale('ar')->isoFormat('MMMM YYYY') . ')',
            ];
        }

        if ($period === 'this_month') {
            return [
                'start_date' => now()->startOfMonth()->format('Y-m-d'),
                'end_date'   => now()->format('Y-m-d'),
                'title'      => 'الشهر الحالي (' . now()->locale('ar')->isoFormat('MMMM YYYY') . ')',
            ];
        }

        if ($period === 'last_week') {
            $lastWeekStart = now()->subWeek()->startOfWeek();
            $lastWeekEnd   = now()->subWeek()->endOfWeek();
            return [
                'start_date' => $lastWeekStart->format('Y-m-d'),
                'end_date'   => $lastWeekEnd->format('Y-m-d'),
                'title'      => 'الأسبوع الماضي (من ' . $lastWeekStart->format('m/d') . ' إلى ' . $lastWeekEnd->format('m/d') . ')',
            ];
        }

        if ($period === 'this_week') {
            $thisWeekStart = now()->startOfWeek();
            return [
                'start_date' => $thisWeekStart->format('Y-m-d'),
                'end_date'   => now()->format('Y-m-d'),
                'title'      => 'الأسبوع الحالي (من ' . $thisWeekStart->format('m/d') . ' إلى ' . now()->format('m/d') . ')',
            ];
        }

        if ($period === 'yesterday') {
            $yesterday = now()->subDay()->format('Y-m-d');
            return [
                'start_date' => $yesterday,
                'end_date'   => $yesterday,
                'title'      => 'أمس (' . Carbon::parse($yesterday)->format('Y/m/d') . ')',
            ];
        }

        try {
            $targetDate = $rawDate ? Carbon::parse($rawDate)->format('Y-m-d') : now()->format('Y-m-d');
        } catch (Throwable $e) {
            $targetDate = now()->format('Y-m-d');
        }

        $displayTitle = Carbon::parse($targetDate)->format('Y/m/d');
        if ($targetDate === now()->format('Y-m-d')) {
            $displayTitle = 'اليوم (' . $displayTitle . ')';
        } elseif ($targetDate === now()->subDay()->format('Y-m-d')) {
            $displayTitle = 'أمس (' . $displayTitle . ')';
        }

        return [
            'start_date' => $targetDate,
            'end_date'   => $targetDate,
            'title'      => $displayTitle,
        ];
    }

    /**
     * معالجة الاستعلام لنمط قاعدة البيانات الواحدة
     */
    protected function handleSingleConnection(array $periodConfig): string
    {
        $currency = config('app.currency', 'SDG');

        try {
            $data = $this->queryExpensesData(null, $periodConfig['start_date'], $periodConfig['end_date']);

            if ($data['total_amount'] <= 0) {
                return "💸 *تقرير المصروفات ({$periodConfig['title']}):*\n"
                     . "✅ لا توجد أي سندات صرف أو مصروفات مسجلة في هذه الفترة.";
            }

            return $this->formatWhatsAppOutput($data, $periodConfig['title'], 'المركز الرئيسي', $currency);

        } catch (Throwable $e) {
            Log::error("ExpensesQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير المصروفات حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة
     */
    protected function handleMultiBranchConnections(array $periodConfig, string $targetBranch, array $availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');
        $connectionsToQuery = $this->resolveConnectionsToQuery($targetBranch, $availableBranchConnections);

        $totalExpenses = 0.0;
        $totalVouchers = 0;
        $categoriesAggregated = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $data = $this->queryExpensesData($connectionName, $periodConfig['start_date'], $periodConfig['end_date']);

                $totalExpenses += $data['total_amount'];
                $totalVouchers += $data['vouchers_count'];

                foreach ($data['breakdown'] as $categoryName => $amount) {
                    if (!isset($categoriesAggregated[$categoryName])) {
                        $categoriesAggregated[$categoryName] = 0.0;
                    }
                    $categoriesAggregated[$categoryName] += $amount;
                }
            } catch (Throwable $e) {
                Log::error("ExpensesQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if ($totalExpenses <= 0) {
            $branchTitle = ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch]))
                ? " في *{$this->branchLabels[$targetBranch]}*"
                : "";

            return "💸 *تقرير المصروفات ({$periodConfig['title']}){$branchTitle}:*\n"
                 . "✅ لا توجد أي سندات صرف أو مصروفات مسجلة في هذه الفترة.";
        }

        arsort($categoriesAggregated);

        $resultData = [
            'total_amount'   => $totalExpenses,
            'vouchers_count' => $totalVouchers,
            'breakdown'      => $categoriesAggregated,
        ];

        $branchTitle = ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch]))
            ? $this->branchLabels[$targetBranch]
            : 'كافة الفروع';

        return $this->formatWhatsAppOutput($resultData, $periodConfig['title'], $branchTitle, $currency);
    }

    /**
     * استعلام سندات الصرف المرتبطة ببنود المصروفات
     */
    protected function queryExpensesData(?string $connection, string $startDate, string $endDate): array
    {
        $query = !empty($connection) ? DB::connection($connection)->table('vouchers') : DB::table('vouchers');

        $rows = $query
            ->whereNull('vouchers.deleted_at')
            ->where('vouchers.voucher_type', 'payment')
            ->where(function ($q) {
                $q->where('vouchers.sub_ledger_type', 'like', '%Expense%')
                  ->orWhere('vouchers.sub_ledger_type', 'expense');
            })
            ->where(function ($q) use ($startDate, $endDate) {
                if ($startDate === $endDate) {
                    $q->whereDate('vouchers.voucher_date', $startDate)
                      ->orWhereDate('vouchers.created_at', $startDate);
                } else {
                    $q->whereBetween('vouchers.voucher_date', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                      ->orWhereBetween('vouchers.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                }
            })
            ->leftJoin('expenses', 'vouchers.sub_ledger_id', '=', 'expenses.id')
            ->select(
                'vouchers.amount',
                'vouchers.notes',
                'expenses.name as expense_name'
            )
            ->get();

        $totalAmount = 0.0;
        $breakdown = [];

        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $totalAmount += $amount;

            $catName = $row->expense_name ? $row->expense_name : 'مصروفات عامة ونثرية';

            if (!isset($breakdown[$catName])) {
                $breakdown[$catName] = 0.0;
            }
            $breakdown[$catName] += $amount;
        }

        arsort($breakdown);

        return [
            'total_amount'   => $totalAmount,
            'vouchers_count' => $rows->count(),
            'breakdown'      => $breakdown,
        ];
    }

    /**
     * تنسيق مخرجات تقرير المصروفات
     */
    protected function formatWhatsAppOutput(array $data, string $periodTitle, string $branchTitle, string $currency): string
    {
        $output = "💸 *تقرير المصروفات والنفقات ({$periodTitle})*\n";
        $output .= "🏢 *النطاق*: {$branchTitle}\n";
        $output .= "-----------------------------------\n";
        $output .= "💰 *إجمالي المنصرف*: *" . number_format($data['total_amount'], 0) . " {$currency}* ({$data['vouchers_count']} سند صرف)\n";
        $output .= "-----------------------------------\n";
        $output .= "📊 *توزيع بنود المصروفات:*\n";

        foreach ($data['breakdown'] as $category => $amount) {
            $percent = ($data['total_amount'] > 0) ? round(($amount / $data['total_amount']) * 100, 1) : 0;
            $output .= "• *{$category}*: " . number_format($amount, 0) . " {$currency} (%{$percent})\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "⏱️ _تم استخراج التقرير من واقع سندات الصرف المعتمدة._";

        return trim($output);
    }
}