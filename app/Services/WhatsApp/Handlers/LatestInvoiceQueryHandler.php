<?php

namespace App\Services\WhatsApp\Handlers;

use App\Services\WhatsApp\Contracts\QueryHandlerInterface;
use App\Services\WhatsApp\Traits\HandlesBranchConnections;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;

class LatestInvoiceQueryHandler implements QueryHandlerInterface
{
    use HandlesBranchConnections;

    /**
     * تسميات طرق الدفع التجارية
     */
    protected array $paymentTypeLabels = [
        'cash'     => 'نقدي (كاش) 💵',
        'card'     => 'شبكة / بنكك 💳',
        'bank'     => 'تحويل بنكي 💳',
        'transfer' => 'تحويل بنكي 💳',
        'credit'   => 'آجل / ذمم 📝',
    ];

    public function getIntentName(): string
    {
        return 'latest_invoice';
    }

    public function getDescription(): string
    {
        return 'استعلام عن تفاصيل وأصناف وأسعار أحدث فاتورة مبيعات مسجلة لعميل محدد بالاسم أو الهاتف عبر الفروع.';
    }

    public function handle(array $parsedIntent): string
    {
        $search = trim($parsedIntent['party_name'] ?? $parsedIntent['customer'] ?? $parsedIntent['search'] ?? '');
        $targetBranch = $parsedIntent['branch'] ?? 'all';

        if (empty($search)) {
            return "⚠️ يرجى تحديد اسم العميل أو هاتفه للاستعلام عن آخر فاتورة (مثال: آخر فاتورة لعميل طارق).";
        }

        $availableBranchConnections = $this->getAvailableBranchConnections();

        if (empty($availableBranchConnections)) {
            return $this->handleSingleConnection($search);
        }

        return $this->handleMultiBranchConnections($search, $targetBranch, $availableBranchConnections);
    }

    /**
     * معالجة الاستعلام لنمط قاعدة البيانات الواحدة
     */
    protected function handleSingleConnection(string $search): string
    {
        try {
            $invoice = $this->findLatestInvoice(null, $search);

            if (!$invoice) {
                return "❌ *لم نجد أي فواتير مبيعات مسجلة للعميل*: \"{$search}\".";
            }

            return $this->formatWhatsAppInvoice($invoice, 'المركز الرئيسي');

        } catch (Throwable $e) {
            Log::error("LatestInvoiceQueryHandler SingleConnection Error: " . $e->getMessage());

            return "⚠️ تعذر استخراج تفاصيل الفاتورة حالياً، يرجى المحاولة لاحقاً.";
        }
    }

    /**
     * معالجة الاستعلام لنمط الفروع المتعددة
     */
    protected function handleMultiBranchConnections(string $search, string $targetBranch, array $availableBranchConnections): string
    {
        $connectionsToQuery = $this->resolveConnectionsToQuery($targetBranch, $availableBranchConnections);
        $latestInvoice = null;
        $foundBranchLabel = 'المركز الرئيسي';

        foreach ($connectionsToQuery as $branchKey => $connectionName) {
            try {
                $invoice = $this->findLatestInvoice($connectionName, $search);

                if ($invoice) {
                    if (!$latestInvoice or Carbon::parse($invoice->created_at)->gt(Carbon::parse($latestInvoice->created_at))) {
                        $latestInvoice = $invoice;
                        $foundBranchLabel = $this->getBranchLabel($branchKey);
                    }
                }
            } catch (Throwable $e) {
                Log::error("LatestInvoiceQueryHandler Error [{$branchKey}]: " . $e->getMessage());
            }
        }

        if (!$latestInvoice) {
            $branchNotice = ($targetBranch !== 'all' and isset($this->branchLabels[$targetBranch]))
                ? " في *{$this->branchLabels[$targetBranch]}*"
                : "";

            return "❌ *لم نجد أي فواتير مبيعات مسجلة للعميل*: \"{$search}\"{$branchNotice}.";
        }

        return $this->formatWhatsAppInvoice($latestInvoice, $foundBranchLabel);
    }

    /**
     * البحث عن أحدث فاتورة مبيعات غير محذوفة للعميل
     */
    protected function findLatestInvoice(?string $connection, string $search): ?Sale
    {
        $query = !empty($connection) ? Sale::on($connection) : Sale::query();

        return $query->with([
                'customer',
                'items.item',
                'items.itemUnit.unit',
            ])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($search) {
                $q->where('customer_name_text', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cQ) use ($search) {
                      $cQ->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            })
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * تنسيق الفاتورة للعرض المالي والتجاري النقي
     */
    protected function formatWhatsAppInvoice(Sale $sale, string $branchLabel): string
    {
        $currency = config('app.currency', 'SDG');
        $customerName = $sale->customer->name ?? $sale->customer_name_text ?? 'عميل نقدي';
        $customerPhone = $sale->customer->phone ?? null;
        $invoiceDate = $sale->invoice_date 
            ? Carbon::parse($sale->invoice_date)->format('Y/m/d h:i A') 
            : Carbon::parse($sale->created_at)->format('Y/m/d h:i A');

        $paymentLabel = $this->paymentTypeLabels[$sale->payment_type] ?? $sale->payment_type;

        $output = "🧾 *تفاصيل آخر فاتورة مبيعات*\n";
        $output .= "🏢 *الفرع*: {$branchLabel}\n";
        $output .= "-----------------------------------\n";
        $output .= "📄 *رقم الفاتورة*: #{$sale->invoice_number}\n";
        $output .= "👤 *العميل*: {$customerName}" . ($customerPhone ? " ({$customerPhone})" : "") . "\n";
        $output .= "📅 *التاريخ*: {$invoiceDate}\n";
        $output .= "💳 *طريقة الدفع*: {$paymentLabel}\n";
        $output .= "-----------------------------------\n";
        $output .= "📦 *بنود الأصناف والكميات:*\n\n";

        foreach ($sale->items as $index => $item) {
            $itemNum = $index + 1;
            $itemName = $item->item->name ?? 'صنف غير محدد';
            $unitName = $item->itemUnit?->unit?->name ?? 'وحدة';
            $qty = (float) $item->quantity;
            $unitPrice = number_format((float) $item->unit_price, 0);
            $total = number_format((float) $item->grand_total, 0);

            $output .= "*{$itemNum}️⃣ {$itemName}*\n";
            $output .= "   ├ الكمية: {$qty} {$unitName}\n";
            $output .= "   ├ السعر: {$unitPrice} {$currency}\n";
            $output .= "   └ الإجمالي: *{$total} {$currency}*\n\n";
        }

        $output .= "-----------------------------------\n";

        if ((float) $sale->discount_amount > 0) {
            $output .= "🏷️ *الخصم*: " . number_format((float) $sale->discount_amount, 0) . " {$currency}\n";
        }

        if ((float) $sale->tax_amount > 0) {
            $output .= "🏛️ *الضريبة*: " . number_format((float) $sale->tax_amount, 0) . " {$currency}\n";
        }

        $output .= "💵 *صافي الفاتورة الإجمالي*: *" . number_format((float) $sale->grand_total, 0) . " {$currency}*\n";

        if (!empty($sale->notes)) {
            $output .= "📝 *ملاحظات*: _{$sale->notes}_\n";
        }

        return trim($output);
    }
}