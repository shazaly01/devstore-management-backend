<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Account;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    /**
     * 1. كشف الحساب التفصيلي (Account Ledger)
     * عرض حركة حساب معين بالتفصيل مع احتساب الرصيد الافتتاحي ما قبل الفترة المحددة
     */
    public function accountLedger(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
        ]);

        $accountId = $request->account_id;
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : null;
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        $account = Account::find($accountId);
        $firstDigit = substr($account->code, 0, 1);

        // تحديد طبيعة الحساب (1 و 4 مدين، الباقي دائن)
        $isDebitNature = in_array($firstDigit, ['1', '4']);

        // أ: احتساب الرصيد الافتتاحي ما قبل تاريخ البداية من واقع أسطر القيود
        $openingQuery = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($fromDate) {
                if ($fromDate) {
                    $q->where('entry_date', '<', $fromDate);
                } else {
                    $q->where('id', 0); // إذا لم يحدد تاريخ، الافتتاحي صفر حتماً لمنع التكرار
                }
            });

        $openingDebit = (float) $openingQuery->sum('debit');
        $openingCredit = (float) $openingQuery->sum('credit');
        $openingBalance = $isDebitNature ? ($openingDebit - $openingCredit) : ($openingCredit - $openingDebit);

        // ب: جلب الحركات داخل النطاق الزمني مع تأمين الحذف الناعم على رأس القيد
        $linesQuery = JournalEntryLine::with('journalEntry')
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate) {
                if ($fromDate) $q->where('entry_date', '>=', $fromDate);
                if ($toDate) $q->where('entry_date', '<=', $toDate);
            });

        $lines = $linesQuery->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entry_lines.id', 'asc')
            ->select('journal_entry_lines.*')
            ->get();

        $runningBalance = $openingBalance;
        $totalPeriodDebit = 0.00;
        $totalPeriodCredit = 0.00;
        $reportLines = [];

        foreach ($lines as $line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            $totalPeriodDebit += $debit;
            $totalPeriodCredit += $credit;

            if ($isDebitNature) {
                $runningBalance += ($debit - $credit);
            } else {
                $runningBalance += ($credit - $debit);
            }

            $reportLines[] = [
                'id'              => $line->id,
                'entry_id'        => $line->journal_entry_id,
                'entry_number'    => $line->journalEntry->entry_number ?? null,
                'entry_date'      => $line->journalEntry->entry_date ? $line->journalEntry->entry_date->format('Y-m-d') : null,
                'entry_type'      => $line->journalEntry->type ?? null,
                'line_notes'      => $line->line_notes ?? $line->journalEntry->notes ?? null,
                'debit'           => $debit,
                'credit'          => $credit,
                'running_balance' => round($runningBalance, 2),
            ];
        }

        $summaryData = [
            'account_id'          => $account->id,
            'account_name'        => $account->name,
            'account_code'        => $account->code,
            'nature'              => $isDebitNature ? 'debit' : 'credit',
            'nature_lbl'          => $isDebitNature ? 'مدين' : 'دائن',
            'opening_balance'     => round($openingBalance, 2),
            'total_period_debit'  => round($totalPeriodDebit, 2),
            'total_period_credit' => round($totalPeriodCredit, 2),
            'closing_balance'     => round($runningBalance, 2),
        ];

        return response()->json([
            'success'      => true,
            'data'         => $reportLines,
            'grandSummary' => $summaryData,
            'meta'         => $summaryData,
        ]);
    }

    /**
     * 2. كشف حساب الكيانات المساعدة (Sub-Ledger Statement)
     */
    public function subLedgerStatement(Request $request): JsonResponse
    {
        $request->validate([
            'sub_ledger_type' => ['required', 'string'],
            'sub_ledger_id'   => ['required', 'integer'],
        ]);

        $type = $request->sub_ledger_type;
        $id = $request->sub_ledger_id;
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : null;
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        $typeMap = [
            'customer' => \App\Models\Customer::class,
            'client'   => \App\Models\Customer::class,
            'supplier' => \App\Models\Supplier::class,
            'treasury' => \App\Models\Treasury::class,
            'bank'     => \App\Models\Bank::class,
            'expense'  => \App\Models\Expense::class,
            'user'     => \App\Models\User::class,
            'designer' => \App\Models\User::class,
            'store'    => \App\Models\Store::class,
        ];

        $normalizedType = strtolower(trim($type));
        if (array_key_exists($normalizedType, $typeMap)) {
            $type = $typeMap[$normalizedType];
        }

        $isDebitNature = in_array($type, [
            \App\Models\Customer::class,
            \App\Models\Bank::class,
            \App\Models\Treasury::class,
        ]);

        if ($fromDate) {
            $openingQuery = JournalEntryLine::where('sub_ledger_type', $type)
                ->where('sub_ledger_id', $id)
                ->whereHas('journalEntry', function ($q) use ($fromDate) {
                    $q->where('entry_date', '<', $fromDate);
                });

            $openingDebit = (float) $openingQuery->sum('debit');
            $openingCredit = (float) $openingQuery->sum('credit');
            $openingBalance = $isDebitNature ? ($openingDebit - $openingCredit) : ($openingCredit - $openingDebit);
        } else {
            $openingBalance = 0.00;
        }

        $linesQuery = JournalEntryLine::with([
            'journalEntry.lines.account',
            'account',
            'journalEntry.sale',
            'journalEntry.purchase',
            'journalEntry.voucher'
        ])
        ->where('sub_ledger_type', $type)
        ->where('sub_ledger_id', $id)
        ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate) {
            if ($fromDate) $q->where('entry_date', '>=', $fromDate);
            if ($toDate) $q->where('entry_date', '<=', $toDate);
        });

        $lines = $linesQuery->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entry_lines.id', 'asc')
            ->select('journal_entry_lines.*')
            ->get();

        $runningBalance = $openingBalance;
        $totalPeriodDebit = 0.00;
        $totalPeriodCredit = 0.00;
        $reportLines = [];

        foreach ($lines as $line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            $totalPeriodDebit += $debit;
            $totalPeriodCredit += $credit;

            if ($isDebitNature) {
                $runningBalance += ($debit - $credit);
            } else {
                $runningBalance += ($credit - $debit);
            }

            $counterpartAccountName = null;
            if ($line->journalEntry && $line->journalEntry->lines) {
                $isCurrentLineDebit = $debit > 0;

                $oppositeLines = $line->journalEntry->lines->filter(function ($entryLine) use ($isCurrentLineDebit) {
                    return $isCurrentLineDebit ? (float)$entryLine->credit > 0 : (float)$entryLine->debit > 0;
                });

                if ($oppositeLines->isNotEmpty()) {
                    $operationalLines = $oppositeLines->filter(function ($oppLine) {
                        $code = $oppLine->account->code ?? '';
                        return !in_array($code, [Account::CODE_INVENTORY, Account::CODE_COGS]);
                    });

                    $finalOppositeLines = $operationalLines->isNotEmpty() ? $operationalLines : $oppositeLines;

                    $accountWeights = [];
                    foreach ($finalOppositeLines as $oppLine) {
                        $accName = $oppLine->account->name ?? null;
                        if ($accName) {
                            $value = $isCurrentLineDebit ? (float)$oppLine->credit : (float)$oppLine->debit;
                            $accountWeights[$accName] = ($accountWeights[$accName] ?? 0) + $value;
                        }
                    }

                    if (!empty($accountWeights)) {
                        arsort($accountWeights);
                        $counterpartAccountName = key($accountWeights);
                    }
                } else {
                    $otherLine = $line->journalEntry->lines->filter(fn($el) => $el->id !== $line->id)->first();
                    $counterpartAccountName = $otherLine->account->name ?? ($line->account->name ?? null);
                }
            } else {
                $counterpartAccountName = $line->account->name ?? null;
            }

            if ($counterpartAccountName) {
                $counterpartAccountName = trim($counterpartAccountName);

                if (str_contains($counterpartAccountName, 'إيرادات') || str_contains($counterpartAccountName, 'مبيعات')) {
                    $counterpartAccountName = 'المبيعات';
                } elseif (str_contains($counterpartAccountName, 'المصروفات') || str_contains($counterpartAccountName, 'مصروفات')) {
                    $counterpartAccountName = 'مصروفات تشغيلية';
                } elseif (str_contains($counterpartAccountName, 'المخزون')) {
                    $counterpartAccountName = 'المخزون الرئيسي';
                } elseif (str_contains($counterpartAccountName, 'الخزائن') || str_contains($counterpartAccountName, 'الخزينة')) {
                    $counterpartAccountName = 'الخزينة الرئيسية';
                } elseif (str_contains($counterpartAccountName, 'البنوك') || str_contains($counterpartAccountName, 'المصارف')) {
                    $counterpartAccountName = 'البنوك';
                } elseif (str_contains($counterpartAccountName, 'العملاء')) {
                    $counterpartAccountName = 'العملاء';
                } elseif (str_contains($counterpartAccountName, 'الموردين')) {
                    $counterpartAccountName = 'الموردين';
                } else {
                    $counterpartAccountName = preg_replace('/^حساب\s+/u', '', $counterpartAccountName);
                    $counterpartAccountName = preg_replace('/\s+الرئيسي$/u', '', $counterpartAccountName);
                    $counterpartAccountName = preg_replace('/\s+الإجمالي$/u', '', $counterpartAccountName);
                    $counterpartAccountName = preg_replace('/\s+الشامل$/u', '', $counterpartAccountName);
                    $counterpartAccountName = preg_replace('/\s+المساعد$/u', '', $counterpartAccountName);
                }
            }

            $sourceType = null;
            $sourceId = null;

            if ($line->journalEntry) {
                if ($line->journalEntry->sale) {
                    $sourceType = 'sale';
                    $sourceId = $line->journalEntry->sale->id;
                } elseif ($line->journalEntry->purchase) {
                    $sourceType = 'purchase';
                    $sourceId = $line->journalEntry->purchase->id;
                } elseif ($line->journalEntry->voucher) {
                    $sourceType = $line->journalEntry->voucher->voucher_type;
                    $sourceId = $line->journalEntry->voucher->id;
                }
            }

            $reportLines[] = [
                'id'            => $line->id,
                'entry_number'  => $line->journalEntry->entry_number ?? null,
                'entry_date'    => $line->journalEntry->entry_date ? $line->journalEntry->entry_date->format('Y-m-d') : null,
                'account_name'  => $counterpartAccountName,
                'line_notes'    => $line->line_notes ?? $line->journalEntry->notes ?? null,
                'debit'         => $debit,
                'credit'        => $credit,
                'running_total' => round($runningBalance, 2),
                'source_type'   => $sourceType,
                'source_id'     => $sourceId,
            ];
        }

        $summaryData = [
            'sub_ledger_type'     => strtolower(class_basename($type)),
            'sub_ledger_id'       => (int) $id,
            'opening_balance'     => round($openingBalance, 2),
            'total_period_debit'  => round($totalPeriodDebit, 2),
            'total_period_credit' => round($totalPeriodCredit, 2),
            'closing_balance'     => round($runningBalance, 2),
        ];

        return response()->json([
            'success'      => true,
            'data'         => $reportLines,
            'grandSummary' => $summaryData,
            'meta'         => $summaryData,
        ]);
    }

    /**
     * 3. ميزان المراجعة (Trial Balance)
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : null;
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        $accounts = Account::orderBy('code', 'asc')->get();

        $trialBalanceData = [];
        $grandTotalDebitPeriod = 0.00;
        $grandTotalCreditPeriod = 0.00;

        foreach ($accounts as $account) {
            $firstDigit = substr($account->code, 0, 1);
            $isDebitNature = in_array($firstDigit, ['1', '4']);

            // 1. احتساب مجاميع الافتتاحي قبل الفترة
            $opQuery = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($fromDate) {
                    if ($fromDate) {
                        $q->where('entry_date', '<', $fromDate);
                    } else {
                        $q->where('id', 0);
                    }
                });
            $opDebit = (float) $opQuery->sum('debit');
            $opCredit = (float) $opQuery->sum('credit');
            $openingBal = $isDebitNature ? ($opDebit - $opCredit) : ($opCredit - $opDebit);

            // 2. حركات الفترة الحالية المقيدة
            $periodQuery = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate) {
                    if ($fromDate) $q->where('entry_date', '>=', $fromDate);
                    if ($toDate) $q->where('entry_date', '<=', $toDate);
                });
            $periodDebit = (float) $periodQuery->sum('debit');
            $periodCredit = (float) $periodQuery->sum('credit');

            // 3. الرصيد الختامي النهائي
            if ($isDebitNature) {
                $closingBal = $openingBal + ($periodDebit - $periodCredit);
            } else {
                $closingBal = $openingBal + ($periodCredit - $periodDebit);
            }

            $hasChildren = $accounts->where('parent_id', $account->id)->count() > 0;
            if (!$hasChildren) {
                $grandTotalDebitPeriod += $periodDebit;
                $grandTotalCreditPeriod += $periodCredit;
            }

            $trialBalanceData[] = [
                'id'              => $account->id,
                'code'            => $account->code,
                'name'            => $account->name,
                'parent_id'       => $account->parent_id,
                'is_parent'       => $hasChildren,
                'nature'          => $isDebitNature ? 'مدين' : 'دائن',
                'opening_balance' => round($openingBal, 2),
                'period_debit'    => round($periodDebit, 2),
                'period_credit'   => round($periodCredit, 2),
                'closing_balance' => round($closingBal, 2),
            ];
        }

        $totalsData = [
            'total_period_debit'  => round($grandTotalDebitPeriod, 2),
            'total_period_credit' => round($grandTotalCreditPeriod, 2),
            'is_balanced'         => round($grandTotalDebitPeriod, 2) === round($grandTotalCreditPeriod, 2),
        ];

        return response()->json([
            'success'      => true,
            'data'         => $trialBalanceData,
            'grandSummary' => $totalsData,
            'totals'       => $totalsData,
        ]);
    }

    /**
     * 4. قائمة الدخل / الأرباح والخسائر (Income Statement)
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : null;
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        $accounts = Account::all();

        $revenues = [];
        $expenses = [];
        $totalRevenues = 0.00;
        $totalExpenses = 0.00;

        foreach ($accounts as $account) {
            $hasChildren = $accounts->where('parent_id', $account->id)->count() > 0;
            if ($hasChildren) continue;

            $firstDigit = substr($account->code, 0, 1);

            $periodQuery = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate) {
                    if ($fromDate) $q->where('entry_date', '>=', $fromDate);
                    if ($toDate) $q->where('entry_date', '<=', $toDate);
                });

            $debit = (float) $periodQuery->sum('debit');
            $credit = (float) $periodQuery->sum('credit');

            // حسابات الإيرادات (تبدأ بـ 3)
            if ($firstDigit === '3') {
                $netRevenue = $credit - $debit;
                if ($netRevenue != 0) {
                    $totalRevenues += $netRevenue;
                    $revenues[] = [
                        'code'    => $account->code,
                        'name'    => $account->name,
                        'balance' => round($netRevenue, 2),
                    ];
                }
            }

            // حسابات المصروفات وتكلفة النشاط (تبدأ بـ 4)
            if ($firstDigit === '4') {
                $netExpense = $debit - $credit;
                if ($netExpense != 0) {
                    $totalExpenses += $netExpense;
                    $expenses[] = [
                        'code'    => $account->code,
                        'name'    => $account->name,
                        'balance' => round($netExpense, 2),
                    ];
                }
            }
        }

        $netProfit = $totalRevenues - $totalExpenses;
        $outcomeType = $netProfit >= 0 ? 'profit' : 'loss';
        $outcomeLbl = $netProfit >= 0 ? 'صافي أرباح النشاط' : 'صافي خسائر النشاط';

        $summaryData = [
            'from_date'      => $fromDate ? $fromDate->format('Y-m-d') : 'تأسيس المنظومة',
            'to_date'        => $toDate ? $toDate->format('Y-m-d') : now()->format('Y-m-d'),
            'total_revenues' => round($totalRevenues, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_profit'     => round($netProfit, 2),
            'outcome_type'   => $outcomeType,
            'outcome_lbl'    => $outcomeLbl,
        ];

        return response()->json([
            'success'        => true,
            'data'           => [
                'revenues' => $revenues,
                'expenses' => $expenses,
            ],
            'grandSummary'   => $summaryData,
            // الحفاظ على المفاتيح المسطحة للتوافق العكسي
            'meta'           => [
                'from' => $summaryData['from_date'],
                'to'   => $summaryData['to_date'],
            ],
            'revenues'       => $revenues,
            'total_revenues' => round($totalRevenues, 2),
            'expenses'       => $expenses,
            'total_expenses' => round($totalExpenses, 2),
            'net_profit'     => round($netProfit, 2),
            'outcome_type'   => $outcomeType,
            'outcome_lbl'    => $outcomeLbl,
        ]);
    }

    /**
     * 5. الميزانية العمومية / المركز المالي (Balance Sheet)
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        $accounts = Account::all();
        $assets = [];
        $liabilitiesAndEquity = [];

        $totalAssets = 0.00;
        $totalLiabilitiesAndEquity = 0.00;

        $totalRevenues = 0.00;
        $totalExpenses = 0.00;

        foreach ($accounts as $account) {
            $hasChildren = $accounts->where('parent_id', $account->id)->count() > 0;
            if ($hasChildren) continue;

            $firstDigit = substr($account->code, 0, 1);

            $historyQuery = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($toDate) {
                    if ($toDate) $q->where('entry_date', '<=', $toDate);
                });

            $debit = (float) $historyQuery->sum('debit');
            $credit = (float) $historyQuery->sum('credit');

            // حساب الإيرادات والمصروفات التراكمية لتحديد صافي أرباح الفترة غير المقفلة
            if ($firstDigit === '3') $totalRevenues += ($credit - $debit);
            if ($firstDigit === '4') $totalExpenses += ($debit - $credit);

            // 1: الأصول (الأصول العادية مدين - دائن، والحسابات العكسية كالإهلاك تقلل الأصول)
            if ($firstDigit === '1') {
                $balance = $debit - $credit;
                if ($balance != 0) {
                    $totalAssets += $balance;
                    $assets[] = [
                        'code'    => $account->code,
                        'name'    => $account->name,
                        'balance' => round($balance, 2),
                    ];
                }
            }

            // 2: الالتزامات وحقوق الملكية (دائن - مدين)
            if ($firstDigit === '2') {
                $balance = $credit - $debit;
                if ($balance != 0) {
                    $totalLiabilitiesAndEquity += $balance;
                    $liabilitiesAndEquity[] = [
                        'code'    => $account->code,
                        'name'    => $account->name,
                        'balance' => round($balance, 2),
                    ];
                }
            }
        }

        $currentPeriodNetProfit = $totalRevenues - $totalExpenses;
        $totalLiabilitiesAndEquity += $currentPeriodNetProfit;

        $isBalanced = round($totalAssets, 2) === round($totalLiabilitiesAndEquity, 2);
        $asOfDate = $toDate ? $toDate->format('Y-m-d') : now()->format('Y-m-d');

        $summaryData = [
            'as_of_date'                   => $asOfDate,
            'total_assets'                 => round($totalAssets, 2),
            'current_period_net_profit'    => round($currentPeriodNetProfit, 2),
            'total_liabilities_and_equity' => round($totalLiabilitiesAndEquity, 2),
            'is_perfectly_balanced'        => $isBalanced,
        ];

        return response()->json([
            'success'                => true,
            'data'                   => [
                'assets'                 => $assets,
                'liabilities_and_equity' => $liabilitiesAndEquity,
            ],
            'grandSummary'           => $summaryData,
            // الحفاظ على البنية القديمة للتوافقية
            'as_of_date'             => $asOfDate,
            'assets'                 => [
                'items'        => $assets,
                'total_assets' => round($totalAssets, 2),
            ],
            'liabilities_and_equity' => [
                'items'                        => $liabilitiesAndEquity,
                'current_period_net_profit'    => round($currentPeriodNetProfit, 2),
                'total_liabilities_and_equity' => round($totalLiabilitiesAndEquity, 2),
            ],
            'is_perfectly_balanced'  => $isBalanced,
        ]);
    }
}