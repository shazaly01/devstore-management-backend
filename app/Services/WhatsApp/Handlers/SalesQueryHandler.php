<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;

class SalesQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'sales_report';
    }

    public function getDescription(): string
    {
        return 'استعلام عن المبيعات والإيرادات وعدد الفواتير لليوم أو أمس أو هذا الأسبوع أو الأسبوع الماضي أو هذا الشهر أو الشهر الماضي أو فرع معين.';
    }

    public function handle(array $parsedIntent): string
    {
        $targetBranch = $parsedIntent['branch'] ?? 'all';$periodConfig = $this->resolveDateRange($parsedIntent);

        $availableBranchConnections =$this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection($periodConfig,$parsedIntent);
        }

        return $this->handleMultiBranchConnections($periodConfig, $targetBranch,$availableBranchConnections);
    }

    /**
     * تحديد النطاق الزمني والعنوان بدقة لدعم الفترات الحالية والسابقة
     */
    protected function resolveDateRange(array $parsedIntent): array
    {
        $period =$parsedIntent['period'] ?? null;
        $rawDate =$parsedIntent['date'] ?? null;

        if ($period === 'last_month') {$lastMonth = now()->subMonth();
            return [
                'type'       => 'range',
                'start_date' => $lastMonth->startOfMonth()->format('Y-m-d'),
                'end_date'   => $lastMonth->endOfMonth()->format('Y-m-d'),
                'title'      => 'الشهر الماضي (' . $lastMonth->locale('ar')->isoFormat('MMMM YYYY') . ')',
                'is_today'   => false,
            ];
        }

        if ($period === 'this_month') {
            return [
                'type'       => 'range',
                'start_date' => now()->startOfMonth()->format('Y-m-d'),
                'end_date'   => now()->format('Y-m-d'),
                'title'      => 'الشهر الحالي (' . now()->locale('ar')->isoFormat('MMMM YYYY') . ')',
                'is_today'   => false,
            ];
        }

        if ($period === 'last_week') {
            $lastWeekStart = now()->subWeek()->startOfWeek();$lastWeekEnd   = now()->subWeek()->endOfWeek();
            return [
                'type'       => 'range',
                'start_date' => $lastWeekStart->format('Y-m-d'),
                'end_date'   => $lastWeekEnd->format('Y-m-d'),
                'title'      => 'الأسبوع الماضي (من ' . $lastWeekStart->format('m/d') . ' إلى ' . $lastWeekEnd->format('m/d') . ')',
                'is_today'   => false,
            ];
        }

        if ($period === 'this_week') {$thisWeekStart = now()->startOfWeek();
            return [
                'type'       => 'range',
                'start_date' => $thisWeekStart->format('Y-m-d'),
                'end_date'   => now()->format('Y-m-d'),
                'title'      => 'الأسبوع الحالي (من ' . $thisWeekStart->format('m/d') . ' إلى ' . now()->format('m/d') . ')',
                'is_today'   => false,
            ];
        }

        if ($period === 'yesterday') {$yesterday = now()->subDay()->format('Y-m-d');
            return [
                'type'       => 'single_date',
                'start_date' => $yesterday,
                'end_date'   => $yesterday,
                'title'      => 'أمس (' . Carbon::parse($yesterday)->format('Y/m/d') . ')',
                'is_today'   => false,
            ];
        }

        try {
            $targetDate = $rawDate ? Carbon::parse($rawDate)->format('Y-m-d') : now()->format('Y-m-d');
        } catch (Throwable $e) {$targetDate = now()->format('Y-m-d');
        }

        $isToday = ($targetDate === now()->format('Y-m-d'));
        $displayTitle = Carbon::parse($targetDate)->format('Y/m/d');

        if ($isToday) {
            $displayTitle = 'اليوم (' . $displayTitle . ')';
        } elseif ($targetDate === now()->subDay()->format('Y-m-d')) {
            $displayTitle = 'أمس (' . $displayTitle . ')';
        }

        return [
            'type'       => 'single_date',
            'start_date' => $targetDate,
            'end_date'   => $targetDate,
            'title'      => $displayTitle,
            'is_today'   => $isToday,
        ];
    }

    /**
     * معالجة الاستعلام لنمط قاعدة البيانات الواحدة
     */
    protected function handleSingleConnection(array $periodConfig, array$parsedIntent): string
    {
        $currency = config('app.currency', 'SDG');

        try {
            $salesData =$this->querySalesData(null, $periodConfig['start_date'],$periodConfig['end_date']);

            $totalSales    = (float) ($salesData->total_amount ?? 0);
            $totalInvoices = (int) ($salesData->invoice_count ?? 0);
            $totalCash     = (float) ($salesData->cash_amount ?? 0);
            $totalCard     = (float) ($salesData->card_amount ?? 0);
            $totalCredit   = (float) ($salesData->credit_amount ?? 0);

            if ($totalInvoices === 0) {
                return "📊 *تقرير المبيعات ({$periodConfig['title']}):*\n"
                     . "⚠️ لا توجد أي فواتير مبيعات مسجلة في هذه الفترة.\n\n"
                     . "💡 *جرّب البحث عن:*\n"
                     . "• `مبيعات اليوم`\n"
                     . "• `مبيعات الأسبوع الماضي`\n"
                     . "• `مبيعات الشهر الماضي`";
            }

            $output = "📊 *تقرير المبيعات ({$periodConfig['title']})*:\n";
            $output .= "-----------------------------------\n";
            $output .= "💵 *الإجمالي*: *" . number_format($totalSales, 0) . " {$currency}* ({$totalInvoices} فاتورة)\n";
            $output .= "├ كاش: " . number_format($totalCash, 0) . " {$currency}\n";
            $output .= "├ بنك/شبكة: " . number_format($totalCard, 0) . " {$currency}\n";
            $output .= "└ آجل: " . number_format($totalCredit, 0) . " {$currency}\n";

            if ($periodConfig['is_today']) {$yesterdayDate = now()->subDay()->format('Y-m-d');
                $yesterdayData =$this->querySalesData(null, $yesterdayDate,$yesterdayDate);
                $yesterdayTotal = (float) ($yesterdayData->total_amount ?? 0);

                $comparisonText =$this->calculateComparisonText($totalSales,$yesterdayTotal);
                if (!empty($comparisonText)) {$output .= "-----------------------------------\n";
                    $output .=$comparisonText;
                }
            }

            return trim($output);

        } catch (Throwable $e) {
            Log::error("SalesQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير المبيعات حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة
     */
    protected function handleMultiBranchConnections(array $periodConfig, string $targetBranch, array$availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');
        $connectionsToQuery =$this->resolveConnectionsToQuery($targetBranch,$availableBranchConnections);

        $totalSales = 0.0;
        $totalInvoices = 0;
        $totalCash = 0.0;
        $totalCard = 0.0;
        $totalCredit = 0.0;
        $yesterdayGrandTotal = 0.0;
        $branchSummaries = [];

        foreach ($connectionsToQuery as $key =>$connectionName) {
            try {
                $salesData = $this->querySalesData($connectionName, $periodConfig['start_date'],$periodConfig['end_date']);

                if ($salesData and (float)$salesData->total_amount > 0) {
                    $branchTotal = (float)$salesData->total_amount;
                    $branchCount = (int)$salesData->invoice_count;

                    $totalSales    +=$branchTotal;
                    $totalInvoices +=$branchCount;
                    $totalCash     += (float)$salesData->cash_amount;
                    $totalCard     += (float)$salesData->card_amount;
                    $totalCredit   += (float)$salesData->credit_amount;

                    $branchLabel =$this->getBranchLabel($key);$branchSummaries[] = "• {$branchLabel}: " . number_format($branchTotal, 0) . " {$currency} ({$branchCount} فاتورة)";
                }

                if ($periodConfig['is_today']) {
                    $yesterdayDate = now()->subDay()->format('Y-m-d');$yData = $this->querySalesData($connectionName, $yesterdayDate,$yesterdayDate);
                    $yesterdayGrandTotal += (float) ($yData->total_amount ?? 0);
                }
            } catch (Throwable $e) {
                Log::error("SalesQueryHandler Error [{$key}]: " . $e->getMessage());$branchLabel = $this->getBranchLabel($key);
                $branchSummaries[] = "• {$branchLabel}: ⚠️ غير متاح حالياً";
            }
        }

        $branchHeader = "";
        if ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch])) {$branchHeader = "🏢 *الفرع*: {$this->branchLabels[$targetBranch]}\n";
        }

        if ($totalInvoices === 0 and empty($branchSummaries)) {
            return $branchHeader
                 . "📊 *تقرير المبيعات ({$periodConfig['title']}):*\n"
                 . "⚠️ لا توجد أي فواتير مبيعات مسجلة في هذه الفترة.\n\n"
                 . "💡 *جرّب البحث عن:*\n"
                 . "• `مبيعات اليوم`\n"
                 . "• `مبيعات الأسبوع الماضي`\n"
                 . "• `مبيعات الشهر الماضي`";
        }

        $output =$branchHeader;
        $output .= "📊 *تقرير المبيعات ({$periodConfig['title']})*:\n";
        $output .= "-----------------------------------\n";
        $output .= "💵 *الإجمالي*: *" . number_format($totalSales, 0) . " {$currency}* ({$totalInvoices} فاتورة)\n";
        $output .= "├ كاش: " . number_format($totalCash, 0) . " {$currency}\n";
        $output .= "├ بنك/شبكة: " . number_format($totalCard, 0) . " {$currency}\n";
        $output .= "└ آجل: " . number_format($totalCredit, 0) . " {$currency}\n";

        if ($periodConfig['is_today']) {
            $comparisonText =$this->calculateComparisonText($totalSales,$yesterdayGrandTotal);
            if (!empty($comparisonText)) {$output .= "-----------------------------------\n";
                $output .=$comparisonText . "\n";
            }
        }

        if ($targetBranch === 'all' and count($branchSummaries) > 1) {$output .= "-----------------------------------\n";
            $output .= "🏢 *تفاصيل الفروع:*\n" . implode("\n", $branchSummaries);
        }

        return trim($output);
    }

    /**
     * استعلام موحد لاستخراج إحصائيات المبيعات
     */
    protected function querySalesData(?string $connection, string $startDate, string$endDate)
    {
        $query = !empty($connection) ? DB::connection($connection)->table('sales') : DB::table('sales');

        return $query
            ->whereNull('deleted_at')
            ->where(function ($q) use ($startDate,$endDate) {
                if ($startDate ===$endDate) {
                    $q->whereDate('invoice_date',$startDate)
                      ->orWhereDate('created_at', $startDate);
                } else {
                    $q->whereBetween('invoice_date', [$startDate . ' 00:00:00',$endDate . ' 23:59:59'])
                      ->orWhereBetween('created_at', [$startDate . ' 00:00:00',$endDate . ' 23:59:59']);
                }
            })
            ->selectRaw("
                COALESCE(SUM(grand_total), 0) as total_amount,
                COUNT(id) as invoice_count,
                COALESCE(SUM(CASE WHEN payment_type = 'cash' THEN grand_total ELSE 0 END), 0) as cash_amount,
                COALESCE(SUM(CASE WHEN payment_type IN ('card', 'bank', 'transfer') THEN grand_total ELSE 0 END), 0) as card_amount,
                COALESCE(SUM(CASE WHEN payment_type NOT IN ('cash', 'card', 'bank', 'transfer') THEN grand_total ELSE 0 END), 0) as credit_amount
            ")
            ->first();
    }

    /**
     * احتساب مقارنة مبيعات اليوم مع مبيعات أمس بنسبة التغير
     */
    protected function calculateComparisonText(float $todayTotal, float$yesterdayTotal): string
    {
        if ($yesterdayTotal <= 0) {
            return "📈 *مقارنة بأمس*: لم تسجل مبيعات أمس.";
        }

        $diff = $todayTotal -$yesterdayTotal;
        $percent = abs(($diff / $yesterdayTotal) * 100);
        $formattedPercent = number_format($percent, 1);

        if ($diff > 0) {
            return "📈 *النمو مقارنة بأمس*: ارتفعت بنسبة *+{$formattedPercent}\%* (+ " . number_format($diff, 0) . ")";
        } elseif ($diff < 0) {
            return "📉 *المقارنة بأمس*: انخفضت بنسبة *-{$formattedPercent}\%* (- " . number_format(abs($diff), 0) . ")";
        }

        return "⚖️ *المقارنة بأمس*: متطابقة تماماً مع مبيعات أمس.";
    }
}