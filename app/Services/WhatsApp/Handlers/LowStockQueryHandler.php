<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LowStockQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'low_stock';
    }

    public function getDescription(): string
    {
        return 'استعلام عن النواقص وأعلى 10 أصناف وخامات منخفضة وصلت للحد الأدنى للمخزون أو قاربت على النفاد.';
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
     * معالجة الاستعلام في نمط قاعدة البيانات الواحدة
     */
    protected function handleSingleConnection(): string
    {
        try {
            $items = $this->fetchLowStockQuery(null);

            if ($items->isEmpty()) {
                return "✅ *ممتاز!* لا توجد أي أصناف أو خامات وصلت للحد الأدنى للمخزون حالياً.";
            }

            $lowStockItems = [];
            foreach ($items as $item) {
                $lowStockItems[] = [
                    'name'          => $item->name,
                    'qty'           => (float) $item->total_qty,
                    'reorder_level' => (float) $item->total_reorder_level,
                    'unit'          => $item->baseUnit->name ?? 'وحدة',
                    'branch'        => 'المركز الرئيسي',
                ];
            }

            return $this->formatWhatsAppOutput($lowStockItems, 'all');

        } catch (Throwable $e) {
            Log::error("LowStockQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير النواقص حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة
     */
    protected function handleMultiBranchConnections(string $targetBranch, array $availableBranchConnections): string
    {
        $connectionsToQuery = $this->resolveConnectionsToQuery($targetBranch, $availableBranchConnections);
        $lowStockItems = [];

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $items = $this->fetchLowStockQuery($connectionName);

                foreach ($items as $item) {
                    $lowStockItems[] = [
                        'name'          => $item->name,
                        'qty'           => (float) $item->total_qty,
                        'reorder_level' => (float) $item->total_reorder_level,
                        'unit'          => $item->baseUnit->name ?? 'وحدة',
                        'branch'        => $this->getBranchLabel($branchKey),
                    ];
                }
            } catch (Throwable $e) {
                Log::error("LowStockQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (empty($lowStockItems)) {
            return "✅ *ممتاز!* لا توجد أي أصناف أو خامات وصلت للحد الأدنى للمخزون حالياً.";
        }

        usort($lowStockItems, fn($a, $b) => $a['qty'] <=> $b['qty']);
        $top10LowStock = array_slice($lowStockItems, 0, 10);

        return $this->formatWhatsAppOutput($top10LowStock, $targetBranch);
    }

    /**
     * استعلام مباشر عالي الكفاءة لقاعدة البيانات بدلاً من الفلترة البرمجية بالذاكرة
     */
    protected function fetchLowStockQuery(?string $connection)
    {
        $query = !empty($connection) ? Item::on($connection) : Item::query();

        return $query->leftJoin('item_stocks', 'items.id', '=', 'item_stocks.item_id')
            ->where('items.item_type', 'product')
            ->where('items.is_active', true)
            ->whereNull('items.deleted_at')
            ->groupBy('items.id', 'items.name', 'items.base_unit_id')
            ->select(
                'items.id',
                'items.name',
                'items.base_unit_id',
                DB::raw('COALESCE(SUM(item_stocks.current_quantity), 0) as total_qty'),
                DB::raw('COALESCE(SUM(item_stocks.reorder_level), 0) as total_reorder_level')
            )
            ->havingRaw('total_qty <= CASE WHEN total_reorder_level > 0 THEN total_reorder_level ELSE 5 END')
            ->orderBy('total_qty', 'asc')
            ->take(10)
            ->with('baseUnit')
            ->get();
    }

    /**
     * تنسيق تقرير النواقص النهائي
     */
    protected function formatWhatsAppOutput(array $items, string $targetBranch): string
    {
        $branchTitle = ($targetBranch !== 'all' && isset($this->branchLabels[$targetBranch]))
            ? "({$this->branchLabels[$targetBranch]})"
            : "(جميع الفروع)";

        $output = "⚠️ *تنبيه النواقص والأصناف الحرجة {$branchTitle}*\n";
        $output .= "-----------------------------------\n";

        foreach ($items as $index => $item) {
            $rank = $index + 1;
            $name = $item['name'];
            $qty = number_format($item['qty'], 2);
            $reorderLevel = $item['reorder_level'] > 0 ? number_format($item['reorder_level'], 2) : 'غير محدد';
            $unit = $item['unit'];
            $branch = $item['branch'];

            $output .= "{$rank}️⃣ *{$name}*\n";
            $output .= "   ├ المتبقي: *{$qty} {$unit}*\n";
            if ($item['reorder_level'] > 0) {
                $output .= "   ├ حد الأمان: {$reorderLevel} {$unit}\n";
            }
            $output .= "   └ الفرع: {$branch}\n\n";
        }

        $output .= "-----------------------------------\n";
        $output .= "💡 *توصية*: يرجى إصدار أذونات الشراء لتفادي توقف العمل.";

        return trim($output);
    }
}