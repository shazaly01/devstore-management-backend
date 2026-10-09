<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Models\Customer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartyBalanceQueryHandler implements QueryHandlerInterface
{
    /**
     * ربط مفاتيح الفروع بأسماء اتصالات قاعدة البيانات
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
        return 'party_balance';
    }

    public function getDescription(): string
    {
        return 'الاستعلام عن رصيد حساب عميل أو جهة تعامل محددة بالاسم أو رقم الهاتف.';
    }

    public function handle(array $parsedIntent): string
    {
        $searchTerm = $parsedIntent['party_name'] 
            ?? $parsedIntent['customer_name'] 
            ?? $parsedIntent['query'] 
            ?? $parsedIntent['search'] 
            ?? null;

        if (empty($searchTerm)) {
            return "⚠️ يرجى تحديد اسم العميل أو رقم هاتفه للاستعلام عن الرصيد (مثال: رصيد أحمد محمد).";
        }

        $targetBranch = $parsedIntent['branch'] ?? 'all';
        $availableBranchConnections = $this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection($searchTerm);
        }

        return $this->handleMultiBranchConnections($searchTerm, $targetBranch, $availableBranchConnections);
    }

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

    protected function handleSingleConnection(string $searchTerm): string
    {
        $currency = config('app.currency', 'SDG');

        try {
            $customers = Customer::whereNull('deleted_at')
                ->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', "%{$searchTerm}%")
                          ->orWhere('phone', 'like', "%{$searchTerm}%");
                })
                ->take(5)
                ->get();

            if ($customers->isEmpty()) {
                return "⚠️ لم يتم العثور على أي عميل يطابق البحث: *{$searchTerm}*.";
            }

            if ($customers->count() === 1) {
                return $this->formatSingleCustomerOutput($customers->first(), $currency);
            }

            return $this->formatMultipleCustomersOutput($customers, $currency);

        } catch (Throwable $e) {
            Log::error("PartyBalanceQueryHandler SingleConnection Error: " . $e->getMessage());
            return "⚠️ تعذر استخراج رصيد العميل حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    protected function handleMultiBranchConnections(string $searchTerm, string $targetBranch, array $availableBranchConnections): string
    {
        $currency = config('app.currency', 'SDG');

        $connectionsToQuery = [];
        if ($targetBranch === 'all' || !isset($availableBranchConnections[$targetBranch])) {
            $connectionsToQuery = $availableBranchConnections;
        } else {
            $connectionsToQuery[$targetBranch] = $availableBranchConnections[$targetBranch];
        }

        $matches = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $customers = Customer::on($connectionName)
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', "%{$searchTerm}%")
                              ->orWhere('phone', 'like', "%{$searchTerm}%");
                    })
                    ->take(5)
                    ->get();

                $branchLabel = $this->branchLabels[$branchKey] ?? "فرع ({$branchKey})";

                foreach ($customers as $customer) {
                    $key = $customer->phone ?: $customer->name;
                    if (!isset($matches[$key])) {
                        $matches[$key] = [
                            'name'     => $customer->name,
                            'phone'    => $customer->phone ?? 'غير مسجل',
                            'balances' => [$branchLabel => (float) $customer->current_balance],
                            'total'    => (float) $customer->current_balance,
                        ];
                    } else {
                        $matches[$key]['balances'][$branchLabel] = (float) $customer->current_balance;
                        $matches[$key]['total'] += (float) $customer->current_balance;
                    }
                }
            } catch (Throwable $e) {
                Log::error("PartyBalanceQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (empty($matches)) {
            return "⚠️ لم يتم العثور على أي عميل يطابق البحث: *{$searchTerm}*.";
        }

        $output = "📄 *نتائج الاستعلام عن رصيد العميل:*\n";
        $output .= "-----------------------------------\n";

        foreach ($matches as $data) {
            $status = $this->getBalanceStatusText($data['total']);
            $output .= "👤 *الاسم:* {$data['name']}\n";
            $output .= "📞 *الهاتف:* {$data['phone']}\n";
            $output .= "💰 *الرصيد الإجمالي:* *" . number_format(abs($data['total']), 0) . " {$currency}* ({$status})\n";

            if (count($data['balances']) > 1) {
                $output .= "📌 *تفاصيل الفروع:*\n";
                foreach ($data['balances'] as $branch => $bal) {
                    $output .= "   └ {$branch}: " . number_format($bal, 0) . " {$currency}\n";
                }
            }
            $output .= "-----------------------------------\n";
        }

        return trim($output);
    }

    protected function formatSingleCustomerOutput(Customer $customer, string $currency): string
    {
        $balance = (float) $customer->current_balance;
        $status = $this->getBalanceStatusText($balance);

        $output = "📄 *بيانات رصيد العميل:*\n";
        $output .= "-----------------------------------\n";
        $output .= "👤 *الاسم:* {$customer->name}\n";
        $output .= "📞 *الهاتف:* " . ($customer->phone ?? 'غير مسجل') . "\n";
        $output .= "💰 *الرصيد الحالي:* *" . number_format(abs($balance), 0) . " {$currency}*\n";
        $output .= "📌 *الحالة:* {$status}\n";
        $output .= "-----------------------------------";

        return $output;
    }

    protected function formatMultipleCustomersOutput($customers, string $currency): string
    {
        $output = "🔍 *تم العثور على أكثر من عميل، يرجى التحديد بدقة:*\n";
        $output .= "-----------------------------------\n";

        foreach ($customers as $index => $customer) {
            $num = $index + 1;
            $balance = (float) $customer->current_balance;
            $status = $this->getBalanceStatusText($balance);
            $output .= "{$num}️⃣ *{$customer->name}* (" . ($customer->phone ?? 'بدون رقم') . ")\n";
            $output .= "   └ الرصيد: *" . number_format(abs($balance), 0) . " {$currency}* ({$status})\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "💡 أعد كتابة الاسم كاملاً أو برقم الهاتف للتدقيق.";

        return $output;
    }

    protected function getBalanceStatusText(float $balance): string
    {
        if ($balance > 0) {
            return "مدين (مستحق على العميل للشركة) 🔴";
        } elseif ($balance < 0) {
            return "دائن (مستحق للعميل على الشركة) 🟢";
        }
        return "حساب متوازن (خالص) ⚪";
    }
}