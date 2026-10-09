<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use App\Models\Item;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Throwable;

class ItemStockQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    public function getIntentName(): string
    {
        return 'item_stock';
    }

    public function getDescription(): string
    {
        return 'استعلام عن رصيد الكميات المتوفرة وأسعار البيع لصنف معين أو عدة أصناف (مثل: رصيد بنر 130، جرد ورق 70 جرام، سعر ورصيد رول اب).';
    }

    public function handle(array $parsedIntent): string
    {
        $search = trim($parsedIntent['item_name'] ?? $parsedIntent['search'] ?? '');
        $targetBranch =$parsedIntent['branch'] ?? 'all';

        if (empty($search)) {
            return "⚠️ يرجى تحديد اسم الصنف أو الباركود للاستعلام عن المخزون.";
        }

        $availableBranchConnections =$this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection($search,$parsedIntent);
        }

        return $this->handleMultiBranchConnections($search, $targetBranch,$availableBranchConnections);
    }

    /**
     * معالجة الاستعلام لنمط قاعدة البيانات الواحدة
     */
    protected function handleSingleConnection(string $search, array$parsedIntent): string
    {
        $normalizedSearch =$this->normalizeArabic($search);$currency = config('app.currency', 'SDG');

        try {
            $branchResults =$this->searchInBranch(null, $search,$normalizedSearch);

            if (empty($branchResults['items']) or$branchResults['items']->isEmpty()) {
                return "❌ *لم نجد أي صنف يطابق*: \"{$search}\"\n\n"
                     . "💡 *نصائح للوصول لنتيجة دقيقة:*\n"
                     . "• اكتب الكلمة الأساسية فقط للصنف (مثال: `رول اب`).\n"
                     . "• يمكنك البحث باستخدام الباركود مباشرة.\n\n"
                     . "📌 *استعلامات أخرى يمكنك تجربتها:*\n"
                     . "• `رصيد بنر 130` - `مبيعات اليوم`";
            }

            $output = "📦 *تقرير توفر المخزون والأسعار*: _{$search}_\n";
            $output .= "-----------------------------------\n";

            foreach ($branchResults['items'] as$item) {
                $output .= "🔹 *{$item['name']}*\n";
                if ($item['price'] !== null and$item['price'] > 0) {
                    $output .= "  ├ السعر: *" . number_format($item['price'], 0) . " {$currency}* ({$item['base_unit_name']})\n";
                }
                $output .= "  ├ إجمالي المخزون: *{$item['total_breakdown']}*\n";

                if ($item['stores']->count() > 1) {
                    foreach ($item['stores'] as $store) {$output .= "  ├─ {$store['store_name']}: {$store['breakdown']}\n";
                    }
                }
            }

            if (!empty($branchResults['has_more'])) {$totalMoreItems = $branchResults['total_count'] -$branchResults['items']->count();
                if ($totalMoreItems > 0) {$output .= "-----------------------------------\n";
                    $output .= "💡 *ملاحظة*: توجد *{$totalMoreItems} أصناف أخرى* تطابق كلمة \"{$search}\".\n";
                    $output .= "يرجى تحديد البحث بدقة (مثال: *{$search} 150*).\n";
                }
            }

            return trim($output);

        } catch (Throwable $e) {
            Log::error("ItemStockQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تقرير المخزون حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة
     */
    protected function handleMultiBranchConnections(string $search, string $targetBranch, array$availableBranchConnections): string
    {
        $connectionsToQuery =$this->resolveConnectionsToQuery($targetBranch,$availableBranchConnections);
        $normalizedSearch =$this->normalizeArabic($search);$results = collect();

        foreach ($connectionsToQuery as $branchKey =>$connectionName) {
            try {
                $branchResults = $this->searchInBranch($connectionName, $search,$normalizedSearch);
                if (!empty($branchResults['items']) and $branchResults['items']->isNotEmpty()) {$results->put($branchKey,$branchResults);
                }
            } catch (Throwable $e) {
                Log::error("ItemStockQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if ($results->isEmpty()) {
            $branchNotice = ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch]))
                ? " في *{$this->branchLabels[$targetBranch]}*"
                : "";

            return "❌ *لم نجد أي صنف يطابق*: \"{$search}\"{$branchNotice}\n\n"
                 . "💡 *نصائح للوصول لنتيجة دقيقة:*\n"
                 . "• اكتب الكلمة الأساسية فقط للصنف (مثال: `رول اب`).\n"
                 . "• تأكد من اختيار الفرع الصحيح.\n"
                 . "• يمكنك البحث باستخدام الباركود مباشرة.\n\n"
                 . "📌 *استعلامات أخرى يمكنك تجربتها:*\n"
                 . "• `رصيد بنر 130` - `مبيعات اليوم`";
        }

        return $this->formatWhatsAppReport($search, $results,$targetBranch);
    }

    /**
     * البحث عن الأصناف ورصيدها مع حل تسعير الوحدة الأساسية
     */
    protected function searchInBranch(?string $connection, string $rawSearch, string$normalizedSearch): array
    {
        $maxResults = 5;

        $query = !empty($connection) ? Item::on($connection) : Item::query();

        $query->with([
            'baseUnit',
            'units.unit',
            'stocks.store',
            'prices',
        ])
        ->where(function ($q) use ($rawSearch,$normalizedSearch) {
            $q->where('name', 'like', "\%{$rawSearch}%")
              ->orWhereRaw("
                    LOWER(
                        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا'), 'ى', 'ي'), 'ة', 'ه')
                    ) LIKE ?", ["%{$normalizedSearch}%"])
              ->orWhereHas('barcodes', function ($bQ) use ($rawSearch) {
                  $bQ->where('barcode', 'like', "\%{$rawSearch}%");
              });
        })
        ->orderByRaw("
            CASE
                WHEN name = ? THEN 1
                WHEN name LIKE ? THEN 2
                ELSE 3
            END
        ", [$rawSearch, "{$rawSearch}%"]);

        $totalMatches = $query->count();$items = $query->take($maxResults)->get();

        $mappedItems = $items->map(function ($item) {
            $baseUnitName =$item->baseUnit->name ?? 'قطعة';

            $storesStock =$item->stocks->map(function ($stock) use ($item) {
                $baseQty = (float)$stock->current_quantity;
                return [
                    'store_name' => $stock->store->name ?? 'المخزن الرئيسي',
                    'base_qty'   => $baseQty,
                    'breakdown'  => $this->calculateUnitBreakdown($item,$baseQty),
                ];
            });

            $totalBaseQty = $storesStock->sum('base_qty');$price = $this->resolveItemBasePrice($item);

            return [
                'id'              => $item->id,
                'name'            => $item->name,
                'price'           => $price,
                'base_unit_name'  => $baseUnitName,
                'total_breakdown' => $this->calculateUnitBreakdown($item,$totalBaseQty),
                'stores'          => $storesStock,
            ];
        });

        return [
            'total_count' => $totalMatches,
            'has_more'    => $totalMatches >$maxResults,
            'items'       => $mappedItems,
        ];
    }

    /**
     * استخراج سعر بيع الوحدة الأساسية للصنف بدقة لمنع العشوائية
     */
    protected function resolveItemBasePrice(Item $item): ?float
    {
        if (!$item->prices or$item->prices->isEmpty()) {
            return null;
        }

        $baseUnitId =$item->base_unit_id;

        // 1. البحث عن سعر يطابق الوحدة الأساسية مباشرة
        $basePriceRecord =$item->prices->first(function ($p) use ($baseUnitId) {
            return (isset($p->unit_id) and (int)$p->unit_id === (int)$baseUnitId)
                or (isset($p->item_unit_id) and (int)$p->item_unit_id === (int)$baseUnitId);
        });

        // 2. إذا لم يتطابق، البحث عبر مصفوفة وحدات الصنف عن الوحدة ذات معامل التحويل 1
        if (!$basePriceRecord and $item->units and$item->units->isNotEmpty()) {
            $baseItemUnit =$item->units->first(function ($u) use ($baseUnitId) {
                return (isset($u->unit_id) and (int)$u->unit_id === (int)$baseUnitId)
                    or (isset($u->conversion_factor) and (float)$u->conversion_factor == 1.0);
            });

            if ($baseItemUnit) {
                $basePriceRecord =$item->prices->first(function ($p) use ($baseItemUnit) {
                    return isset($p->item_unit_id) and (int)$p->item_unit_id === (int)$baseItemUnit->id;
                });
            }
        }

        // 3. في حال عدم وجود تخصيص، نأخذ أول سعر بيع موجب مسجل
        if (!$basePriceRecord) {
            $basePriceRecord =$item->prices->first(function ($p) {$val = (float) ($p->price ?? $p->selling_price ?? 0);
                return $val > 0;
            });
        }

        if ($basePriceRecord) {$val = (float) ($basePriceRecord->price ?? $basePriceRecord->selling_price ?? 0);
            return $val > 0 ?$val : null;
        }

        return null;
    }

    /**
     * تفكيك الكميات بناءً على مصفوفة الوحدات
     */
    protected function calculateUnitBreakdown(Item $item, float$baseQty): string
    {
        $baseUnitName =$item->baseUnit->name ?? 'قطعة';

        if ($baseQty == 0) {
            return "0 {$baseUnitName}";
        }

        $units =$item->units
            ->filter(fn($u) => (float)$u->conversion_factor > 1)
            ->sortByDesc(fn($u) => (float)$u->conversion_factor);

        if ($units->isEmpty()) {
            return "{$baseQty} {$baseUnitName}";
        }

        $remainingQty = abs($baseQty);$parts = [];

        foreach ($units as$itemUnit) {
            $factor = (float)$itemUnit->conversion_factor;
            $unitName =$itemUnit->unit->name ?? 'وحدة';

            if ($remainingQty >= $factor) {$unitQty = floor($remainingQty / $factor);
                $remainingQty = fmod($remainingQty, $factor);$parts[] = "{$unitQty} {$unitName}";
            }
        }

        if ($remainingQty > 0 or empty($parts)) {
            $formattedRemaining = (float)$remainingQty;
            $parts[] = "{$formattedRemaining} {$baseUnitName}";
        }

        $result = implode(' و ', $parts);

        return $baseQty < 0 ? "-{$result}" : $result;
    }

    /**
     * تنسيق تقرير المخزون النهائي لرسائل الواتساب
     */
    protected function formatWhatsAppReport(string $search, Collection $results, string$targetBranch): string
    {
        $currency = config('app.currency', 'SDG');
        $output = "📦 *تقرير توفر المخزون والأسعار*: _{$search}_\n";
        $output .= "-----------------------------------\n";

        $totalMoreItems = 0;

        foreach ($results as$branchKey => $branchData) {$label = $this->getBranchLabel($branchKey);
            $output .= "🏢 *الفرع*: {$label}\n";

            foreach ($branchData['items'] as$item) {
                $output .= "🔹 *{$item['name']}*\n";
                if ($item['price'] !== null and$item['price'] > 0) {
                    $output .= "  ├ السعر: *" . number_format($item['price'], 0) . " {$currency}* ({$item['base_unit_name']})\n";
                }
                $output .= "  ├ إجمالي المخزون: *{$item['total_breakdown']}*\n";

                if ($item['stores']->count() > 1) {
                    foreach ($item['stores'] as $store) {$output .= "  ├─ {$store['store_name']}: {$store['breakdown']}\n";
                    }
                }
            }

            if (!empty($branchData['has_more'])) {$totalMoreItems += ($branchData['total_count'] -$branchData['items']->count());
            }

            $output .= "\n";
        }

        if ($totalMoreItems > 0) {$output .= "-----------------------------------\n";
            $output .= "💡 *ملاحظة*: توجد *{$totalMoreItems} أصناف أخرى* تطابق كلمة \"{$search}\".\n";
            $output .= "يرجى تحديد البحث بدقة (مثال: *{$search} 150*).\n";
        }

        return trim($output);
    }

    /**
     * تطبيع وتجريد النصوص العربية لتسهيل المطابقة
     */
    protected function normalizeArabic(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '',$text);
        $text = preg_replace('/[أإآ]/u', 'ا', $text);
        $text = preg_replace('/ى/u', 'ي', $text);
        $text = preg_replace('/ة/u', 'ه', $text);

        return trim(mb_strtolower($text));
    }
}