<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\ItemStock;
use App\Models\JournalEntryLine;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\Treasury;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * المحرك الرئيسي لجلب كافة مؤشرات وإحصائيات لوحة تحكم المدير الشاملة
     */
    public function getManagerStats(array $filters): array
    {
        $fromDate = isset($filters['from_date'])
            ? Carbon::parse($filters['from_date'])->startOfDay()
            : Carbon::now()->startOfMonth()->startOfDay();

        $toDate = isset($filters['to_date'])
            ? Carbon::parse($filters['to_date'])->endOfDay()
            : Carbon::now()->endOfMonth()->endOfDay();

        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }

        // عدد الأيام المحدد لفحص انتهاء الصلاحية (الافتراضي 30 يوماً)
        $expiryDays = isset($filters['expiry_days']) && (int) $filters['expiry_days'] > 0
            ? min(3650, (int) $filters['expiry_days'])
            : 30;

        // حساب الفترة السابقة المساوية في المدة للمقارنة ونسب النمو
        $daysCount = $fromDate->copy()->startOfDay()->diffInDays($toDate->copy()->startOfDay()) + 1;
        $prevToDate = $fromDate->copy()->subDay()->endOfDay();
        $prevFromDate = $prevToDate->copy()->subDays($daysCount - 1)->startOfDay();

        // 1. الأصناف الأكثر مبيعاً، تنبيهات حد الطلب، الأصناف قريبة الانتهاء، وأحجام الفواتير
        $topSellingItems = $this->getTopSellingItems($fromDate, $toDate);
        $reorderItems = $this->getReorderLevelItems();
        $expiringItems = $this->getExpiringItems($expiryDays);
        $operationalStats = $this->getOperationalStats($fromDate, $toDate);

        // 2. بيانات الرسم البياني للمبيعات والمشتريات وطرق التحصيل خلال الفترة
        $salesAnalytics = $this->buildSalesAndPurchasesAnalytics($fromDate, $toDate, $daysCount);

        // 3. حساب صافي الأرباح المحاسبية (الفترة الحالية والسابقة والسلسلة الزمنية)
        $profitAnalytics = $this->buildProfitAnalytics(
            $fromDate,
            $toDate,
            $prevFromDate,
            $prevToDate,
            $salesAnalytics['bucket_keys'],
            $salesAnalytics['is_monthly']
        );

        // 4. إجماليات الفترة السابقة للمبيعات والمشتريات لحساب نسب النمو
        $prevNetSales = $this->calculatePeriodNetSales($prevFromDate, $prevToDate);
        $prevNetPurchases = $this->calculatePeriodNetPurchases($prevFromDate, $prevToDate);

        // 5. السيولة المتاحة والأرصدة التراكمية اللحظية
        $liquidityStats = $this->getLiquidityAndBalances();

        // 6. عدادات التنبيهات الإجرائية العاجلة
        $alerts = $this->buildOperationalAlerts(count($reorderItems), count($expiringItems));

        return [
            'date_range' => [
                'from' => $fromDate->toDateString(),
                'to'   => $toDate->toDateString(),
            ],
            'expiry_days'         => $expiryDays,
            'net_sales_amount'    => round((float) $salesAnalytics['totals']['net_sales'], 2),
            'top_selling_items'   => $topSellingItems,
            'reorder_level_items' => $reorderItems,
            'expiring_items'      => $expiringItems,
            'operational_volume'  => $operationalStats,

            // كروت المؤشرات الكبرى (KPIs) مع نسب النمو والـ Sparklines
            'kpis' => [
                'net_sales' => [
                    'value'             => round((float) $salesAnalytics['totals']['net_sales'], 2),
                    'previous_value'    => round($prevNetSales, 2),
                    'growth_percentage' => $this->calculateGrowthPercentage((float) $salesAnalytics['totals']['net_sales'], $prevNetSales),
                    'sparkline'         => $salesAnalytics['chart']['net_sales'],
                ],
                'net_profit' => [
                    'value'             => round((float) $profitAnalytics['current_net_profit'], 2),
                    'previous_value'    => round((float) $profitAnalytics['previous_net_profit'], 2),
                    'growth_percentage' => $this->calculateGrowthPercentage(
                        (float) $profitAnalytics['current_net_profit'],
                        (float) $profitAnalytics['previous_net_profit']
                    ),
                    'sparkline'         => $profitAnalytics['sparkline'],
                    'revenue'           => round((float) $profitAnalytics['current_revenue'], 2),
                    'cogs'              => round((float) $profitAnalytics['current_cogs'], 2),
                    'expenses'          => round((float) $profitAnalytics['current_expenses'], 2),
                ],
                'net_purchases' => [
                    'value'             => round((float) $salesAnalytics['totals']['net_purchases'], 2),
                    'previous_value'    => round($prevNetPurchases, 2),
                    'growth_percentage' => $this->calculateGrowthPercentage((float) $salesAnalytics['totals']['net_purchases'], $prevNetPurchases),
                    'sparkline'         => $salesAnalytics['chart']['net_purchases'],
                ],
                'total_liquidity' => $liquidityStats,
            ],

            // بيانات الرسم البياني للمبيعات خلال الفترة
            'sales_chart' => $salesAnalytics['chart'],

            // كبسولات التنبيهات الإجرائية
            'alerts' => $alerts,
        ];
    }

    /**
     * بناء السلاسل الزمنية للمبيعات والمرتجعات والمشتريات وتفصيل التحصيل خلال الفترة
     */
    private function buildSalesAndPurchasesAnalytics(Carbon $from, Carbon $to, int $daysCount): array
    {
        $isMonthly = $daysCount > 62;
        $bucketKeys = [];
        $labels = [];

        if ($isMonthly) {
            $cursor = $from->copy()->startOfMonth();
            $endMonth = $to->copy()->startOfMonth();
            while ($cursor->lte($endMonth)) {
                $key = $cursor->format('Y-m');
                $bucketKeys[] = $key;
                $labels[] = $key;
                $cursor->addMonth();
            }
        } else {
            $period = CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay());
            foreach ($period as $date) {
                $bucketKeys[] = $date->format('Y-m-d');
                $labels[] = $date->format('m-d');
            }
        }

        $grossSalesMap = array_fill_keys($bucketKeys, 0.0);
        $returnsMap = array_fill_keys($bucketKeys, 0.0);
        $netSalesMap = array_fill_keys($bucketKeys, 0.0);
        $netPurchasesMap = array_fill_keys($bucketKeys, 0.0);

        $cashCollected = 0.0;
        $bankCollected = 0.0;
        $creditSales = 0.0;

        $hasSplitColumns = Schema::hasColumn('sales', 'cash_amount') && Schema::hasColumn('sales', 'bank_amount');
        $selectColumns = [
            'invoice_type',
            'invoice_date',
            'grand_total',
            'payment_type',
        ];
        if ($hasSplitColumns) {
            $selectColumns[] = 'cash_amount';
            $selectColumns[] = 'bank_amount';
        }

        $sales = Sale::whereBetween('invoice_date', [$from, $to])
            ->select($selectColumns)
            ->get();

        foreach ($sales as $sale) {
            $key = $isMonthly
                ? Carbon::parse($sale->invoice_date)->format('Y-m')
                : Carbon::parse($sale->invoice_date)->format('Y-m-d');

            $total = (float) ($sale->grand_total ?? 0);

            if ($sale->invoice_type === 'sale') {
                if (isset($grossSalesMap[$key])) {
                    $grossSalesMap[$key] += $total;
                    $netSalesMap[$key] += $total;
                }

                if ($hasSplitColumns && ($sale->cash_amount !== null || $sale->bank_amount !== null)) {
                    $cashPart = round((float) ($sale->cash_amount ?? 0), 2);
                    $bankPart = round((float) ($sale->bank_amount ?? 0), 2);
                    $creditPart = round(max(0.0, $total - $cashPart - $bankPart), 2);

                    $cashCollected += $cashPart;
                    $bankCollected += $bankPart;
                    $creditSales += $creditPart;
                } else {
                    if ($sale->payment_type === 'cash') {
                        $cashCollected += $total;
                    } elseif ($sale->payment_type === 'card') {
                        $bankCollected += $total;
                    } else {
                        $creditSales += $total;
                    }
                }
            } else {
                if (isset($returnsMap[$key])) {
                    $returnsMap[$key] += $total;
                    $netSalesMap[$key] -= $total;
                }

                if ($sale->payment_type === 'cash') {
                    $cashCollected -= $total;
                } elseif ($sale->payment_type === 'card') {
                    $bankCollected -= $total;
                } else {
                    $creditSales -= $total;
                }
            }
        }

        $purchases = Purchase::whereBetween('invoice_date', [$from, $to])
            ->select(['invoice_type', 'invoice_date', 'grand_total'])
            ->get();

        foreach ($purchases as $purchase) {
            $key = $isMonthly
                ? Carbon::parse($purchase->invoice_date)->format('Y-m')
                : Carbon::parse($purchase->invoice_date)->format('Y-m-d');

            $total = (float) ($purchase->grand_total ?? 0);
            if (isset($netPurchasesMap[$key])) {
                $netPurchasesMap[$key] += ($purchase->invoice_type === 'purchase' ? $total : -$total);
            }
        }

        $grossSeries = array_map(fn ($v) => round($v, 2), array_values($grossSalesMap));
        $returnsSeries = array_map(fn ($v) => round($v, 2), array_values($returnsMap));
        $netSalesSeries = array_map(fn ($v) => round($v, 2), array_values($netSalesMap));
        $netPurchasesSeries = array_map(fn ($v) => round($v, 2), array_values($netPurchasesMap));

        return [
            'is_monthly'  => $isMonthly,
            'bucket_keys' => $bucketKeys,
            'totals' => [
                'gross_sales'   => array_sum($grossSeries),
                'returns'       => array_sum($returnsSeries),
                'net_sales'     => array_sum($netSalesSeries),
                'net_purchases' => array_sum($netPurchasesSeries),
            ],
            'chart' => [
                'labels'        => $labels,
                'dates'         => $bucketKeys,
                'gross_sales'   => $grossSeries,
                'returns'       => $returnsSeries,
                'net_sales'     => $netSalesSeries,
                'net_purchases' => $netPurchasesSeries,
                'payment_breakdown' => [
                    'cash'   => round(max(0.0, $cashCollected), 2),
                    'bank'   => round(max(0.0, $bankCollected), 2),
                    'credit' => round(max(0.0, $creditSales), 2),
                ],
            ],
        ];
    }

    /**
     * حساب صافي الأرباح المحاسبية من دفتر اليومية (مع استبعاد قيود الأرصدة الافتتاحية)
     */
    private function buildProfitAnalytics(
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
        array $bucketKeys,
        bool $isMonthly
    ): array {
        $revenueCodes = [Account::CODE_INCOME, Account::CODE_SURPLUS_INCOME];
        $cogsCodes = [Account::CODE_COGS];
        $expenseCodes = [
            Account::CODE_EXPENSES,
            Account::CODE_SHORTAGE_EXPENSE,
            Account::CODE_DESIGN_EXPENSES,
            Account::CODE_EMPLOYEE_EXPENSES,
        ];

        $revenueAccountIds = $this->resolveAccountTreeIds($revenueCodes);
        $cogsAccountIds = $this->resolveAccountTreeIds($cogsCodes);
        $expenseAccountIds = $this->resolveAccountTreeIds($expenseCodes);

        $allTargetIds = array_values(array_unique(array_merge(
            $revenueAccountIds,
            $cogsAccountIds,
            $expenseAccountIds
        )));

        if (empty($allTargetIds)) {
            return [
                'current_revenue'     => 0.0,
                'current_cogs'        => 0.0,
                'current_expenses'    => 0.0,
                'current_net_profit'  => 0.0,
                'previous_net_profit' => 0.0,
                'sparkline'           => array_fill(0, count($bucketKeys), 0.0),
            ];
        }

        $profitSeriesMap = array_fill_keys($bucketKeys, 0.0);
        $currentRevenue = 0.0;
        $currentCogs = 0.0;
        $currentExpenses = 0.0;

        $currentLines = JournalEntryLine::join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.type', '!=', 'opening_balance')
            ->whereIn('journal_entry_lines.account_id', $allTargetIds)
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->select([
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entries.entry_date',
            ])
            ->get();

        foreach ($currentLines as $line) {
            $accId = (int) $line->account_id;
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;
            $key = $isMonthly
                ? Carbon::parse($line->entry_date)->format('Y-m')
                : Carbon::parse($line->entry_date)->format('Y-m-d');

            $effect = 0.0;
            if (in_array($accId, $revenueAccountIds, true)) {
                $val = $credit - $debit;
                $currentRevenue += $val;
                $effect = $val;
            } elseif (in_array($accId, $cogsAccountIds, true)) {
                $val = $debit - $credit;
                $currentCogs += $val;
                $effect = -$val;
            } elseif (in_array($accId, $expenseAccountIds, true)) {
                $val = $debit - $credit;
                $currentExpenses += $val;
                $effect = -$val;
            }

            if (isset($profitSeriesMap[$key])) {
                $profitSeriesMap[$key] += $effect;
            }
        }

        $prevRevenue = 0.0;
        $prevCogs = 0.0;
        $prevExpenses = 0.0;

        $prevLines = JournalEntryLine::join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.type', '!=', 'opening_balance')
            ->whereIn('journal_entry_lines.account_id', $allTargetIds)
            ->whereBetween('journal_entries.entry_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->select([
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            ])
            ->get();

        foreach ($prevLines as $line) {
            $accId = (int) $line->account_id;
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            if (in_array($accId, $revenueAccountIds, true)) {
                $prevRevenue += ($credit - $debit);
            } elseif (in_array($accId, $cogsAccountIds, true)) {
                $prevCogs += ($debit - $credit);
            } elseif (in_array($accId, $expenseAccountIds, true)) {
                $prevExpenses += ($debit - $credit);
            }
        }

        $currentNetProfit = $currentRevenue - $currentCogs - $currentExpenses;
        $previousNetProfit = $prevRevenue - $prevCogs - $prevExpenses;

        return [
            'current_revenue'     => $currentRevenue,
            'current_cogs'        => $currentCogs,
            'current_expenses'    => $currentExpenses,
            'current_net_profit'  => $currentNetProfit,
            'previous_net_profit' => $previousNetProfit,
            'sparkline'           => array_map(fn ($v) => round($v, 2), array_values($profitSeriesMap)),
        ];
    }

    /**
     * جلب معرفات الحسابات وأبنائها المباشرين في شجرة الحسابات
     */
    private function resolveAccountTreeIds(array $codes): array
    {
        $rootIds = Account::whereIn('code', $codes)->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        if (empty($rootIds)) {
            return [];
        }

        $childIds = Account::whereIn('parent_id', $rootIds)->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        return array_values(array_unique(array_merge($rootIds, $childIds)));
    }

    /**
     * حساب صافي المبيعات لفترة محددة
     */
    private function calculatePeriodNetSales(Carbon $from, Carbon $to): float
    {
        $result = Sale::whereBetween('invoice_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN invoice_type = 'sale' THEN grand_total ELSE -grand_total END) as net_sales")
            ->first();

        return (float) ($result->net_sales ?? 0.0);
    }

    /**
     * حساب صافي المشتريات لفترة محددة
     */
    private function calculatePeriodNetPurchases(Carbon $from, Carbon $to): float
    {
        $result = Purchase::whereBetween('invoice_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN invoice_type = 'purchase' THEN grand_total ELSE -grand_total END) as net_purchases")
            ->first();

        return (float) ($result->net_purchases ?? 0.0);
    }

    /**
     * جلب السيولة اللحظية في الخزائن والبنوك وإجمالي ذمم العملاء والموردين وقيمة المخزون
     */
    private function getLiquidityAndBalances(): array
    {
        $treasuriesBalance = (float) Treasury::where('is_active', true)->sum('current_balance');
        $banksBalance = (float) Bank::where('is_active', true)->sum('current_balance');
        $customersReceivables = (float) Customer::where('current_balance', '>', 0)->sum('current_balance');
        $suppliersPayables = (float) Supplier::where('current_balance', '>', 0)->sum('current_balance');
        $inventoryValue = (float) (Account::where('code', Account::CODE_INVENTORY)->value('current_balance') ?? 0.0);

        return [
            'value'                 => round($treasuriesBalance + $banksBalance, 2),
            'treasuries_balance'    => round($treasuriesBalance, 2),
            'banks_balance'         => round($banksBalance, 2),
            'customers_receivables' => round($customersReceivables, 2),
            'suppliers_payables'    => round($suppliersPayables, 2),
            'inventory_value'       => round($inventoryValue, 2),
        ];
    }

    /**
     * تجميع عدادات التنبيهات الإجرائية العاجلة للمدير
     */
    private function buildOperationalAlerts(int $reorderCount, int $expiringSoonCount): array
    {
        $overCreditCustomersCount = 0;
        if (Schema::hasColumn('customers', 'credit_limit')) {
            $overCreditCustomersCount = Customer::where('credit_limit', '>', 0)
                ->whereColumn('current_balance', '>', 'credit_limit')
                ->count();
        }

        return [
            'reorder_count'               => $reorderCount,
            'expiring_soon_count'         => $expiringSoonCount,
            'over_credit_customers_count' => $overCreditCustomersCount,
        ];
    }

    /**
     * حساب نسبة النمو المئوية بين فترتين بأمان
     */
    private function calculateGrowthPercentage(float $current, float $previous): float
    {
        if (abs($previous) < 0.0001) {
            if (abs($current) < 0.0001) {
                return 0.0;
            }
            return $current > 0 ? 100.0 : -100.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * جلب الأصناف الأكثر مبيعاً خلال الفترة (حسب صافي الكمية بالوحدة الأساسية وإجمالي المبيعات)
     */
    private function getTopSellingItems(Carbon $from, Carbon $to): array
    {
        return SaleItem::join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('items', 'sale_items.item_id', '=', 'items.id')
            ->leftJoin('item_units', 'sale_items.item_unit_id', '=', 'item_units.id')
            ->leftJoin('units', 'items.base_unit_id', '=', 'units.id')
            ->whereNull('sales.deleted_at')
            ->whereNull('items.deleted_at')
            ->whereBetween('sales.invoice_date', [$from, $to])
            ->select([
                'sale_items.item_id',
                'items.name as item_name',
                'units.name as unit_name',
                DB::raw("SUM(CASE WHEN sales.invoice_type = 'sale'
                    THEN (sale_items.quantity * COALESCE(item_units.conversion_factor, 1))
                    ELSE -(sale_items.quantity * COALESCE(item_units.conversion_factor, 1))
                    END) as total_quantity"),
                DB::raw("SUM(CASE WHEN sales.invoice_type = 'sale'
                    THEN sale_items.grand_total
                    ELSE -sale_items.grand_total
                    END) as total_amount"),
            ])
            ->groupBy('sale_items.item_id', 'items.name', 'units.name')
            ->havingRaw('total_quantity > 0 OR total_amount > 0')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'item_id'        => (int) $row->item_id,
                    'item_name'      => $row->item_name,
                    'unit_name'      => $row->unit_name ?? 'وحدة',
                    'total_quantity' => round((float) $row->total_quantity, 2),
                    'total_amount'   => round((float) $row->total_amount, 2),
                ];
            })
            ->toArray();
    }

    /**
     * جلب الأصناف التي قاربت صلاحيتها على الانتهاء (أو المنتهية بالفعل) خلال عدد الأيام المحدد
     */
    private function getExpiringItems(int $expiryDays): array
    {
        if (!Schema::hasColumn('items', 'expiry_date')) {
            return [];
        }

        $thresholdDate = Carbon::now()->addDays($expiryDays)->endOfDay();
        $today = Carbon::now()->startOfDay();

        return ItemStock::join('items', 'item_stocks.item_id', '=', 'items.id')
            ->join('stores', 'item_stocks.store_id', '=', 'stores.id')
            ->leftJoin('units', 'items.base_unit_id', '=', 'units.id')
            ->whereNull('items.deleted_at')
            ->where('items.is_active', true)
            ->whereNotNull('items.expiry_date')
            ->where('item_stocks.current_quantity', '>', 0)
            ->whereDate('items.expiry_date', '<=', $thresholdDate)
            ->orderBy('items.expiry_date', 'asc')
            ->select([
                'items.id as item_id',
                'items.name as item_name',
                'items.expiry_date',
                'stores.name as store_name',
                'units.name as unit_name',
                'item_stocks.current_quantity',
            ])
            ->get()
            ->map(function ($row) use ($today) {
                $expiryDate = Carbon::parse($row->expiry_date)->startOfDay();
                $daysRemaining = (int) $today->diffInDays($expiryDate, false);

                return [
                    'item_id'          => (int) $row->item_id,
                    'item_name'        => $row->item_name,
                    'store_name'       => $row->store_name,
                    'unit_name'        => $row->unit_name ?? 'وحدة',
                    'current_quantity' => round((float) $row->current_quantity, 2),
                    'expiry_date'      => $expiryDate->toDateString(),
                    'days_remaining'   => $daysRemaining,
                    'is_expired'       => $daysRemaining < 0,
                ];
            })
            ->toArray();
    }

    /**
     * جلب الأصناف التي تجاوزت حد الطلب الفعلي المكون مسبقاً
     */
    private function getReorderLevelItems(): array
    {
        return ItemStock::join('items', 'item_stocks.item_id', '=', 'items.id')
            ->join('stores', 'item_stocks.store_id', '=', 'stores.id')
            ->whereNull('items.deleted_at')
            ->where('items.is_active', true)
            ->where('item_stocks.reorder_level', '>', 0.00)
            ->whereRaw('item_stocks.current_quantity <= item_stocks.reorder_level')
            ->orderBy('item_stocks.current_quantity')
            ->select([
                'items.id as item_id',
                'items.name as item_name',
                'stores.name as store_name',
                'item_stocks.current_quantity',
                'item_stocks.reorder_level',
            ])
            ->get()
            ->map(function ($stock) {
                return [
                    'item_id'          => $stock->item_id,
                    'item_name'        => $stock->item_name,
                    'store_name'       => $stock->store_name,
                    'current_quantity' => (float) $stock->current_quantity,
                    'reorder_level'    => (float) $stock->reorder_level,
                ];
            })
            ->toArray();
    }

    /**
     * جلب مؤشرات أحجام فواتير المبيعات والمرتجعات خلال الفترة
     */
    private function getOperationalStats(Carbon $from, Carbon $to): array
    {
        $invoiceCounts = DB::table('sales')
            ->whereNull('deleted_at')
            ->whereBetween('invoice_date', [$from, $to])
            ->select([
                DB::raw("COUNT(CASE WHEN invoice_type = 'sale' THEN 1 END) as total_sales_invoices"),
                DB::raw("COUNT(CASE WHEN invoice_type = 'return' THEN 1 END) as total_return_invoices"),
            ])
            ->first();

        return [
            'total_sales_invoices'  => (int) ($invoiceCounts->total_sales_invoices ?? 0),
            'total_return_invoices' => (int) ($invoiceCounts->total_return_invoices ?? 0),
        ];
    }
}