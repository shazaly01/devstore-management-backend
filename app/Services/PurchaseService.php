<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\JournalEntry;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\Store;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\ItemUnitPrice;
use App\Models\ItemStock;
use App\Models\Treasury;
use App\Models\Bank;
use App\Services\StockMovementService;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Exception;

class PurchaseService
{
    protected $stockService;
    protected $journalService;

    public function __construct(StockMovementService $stockService, JournalEntryService $journalService)
    {
        $this->stockService = $stockService;
        $this->journalService = $journalService;
    }

    /**
     * معالجة وحفظ فاتورة مشتريات أو مرتجع جديدة بالكامل مع دعم التكلفة بالعملة الأجنبية
     */
    public function createPurchase(array $data, int $userId): Purchase
    {
        return DB::transaction(function () use ($data, $userId) {

            $purchaseData = array_merge($data, ['user_id' => $userId]);
            $items = $purchaseData['items'];
            unset($purchaseData['items']);

            $purchase = Purchase::create($purchaseData);

            foreach ($items as $item) {
                // جلب سطر الوحدة المحدد من مصفوفة الأصناف لضمان سلامة معاملات التحويل والتكلفة
                $itemUnit = ItemUnit::with('unit')
                    ->where('item_id', $item['item_id'])
                    ->where('id', $item['item_unit_id'])
                    ->firstOrFail();

                $purchaseItem = $purchase->items()->create([
                    'item_id'           => $item['item_id'],
                    'item_unit_id'      => $itemUnit->id,
                    'quantity'          => $item['quantity'],
                    'unit_cost'         => $item['unit_cost'],
                    'foreign_unit_cost' => $item['foreign_unit_cost'] ?? null,
                    'profit_margin'     => $item['profit_margin'] ?? 0.00,
                    'selling_price'     => $item['selling_price'] ?? null,
                    'expiry_date'       => $item['expiry_date'] ?? null,
                    'subtotal'          => $item['subtotal'],
                    'discount_amount'   => $item['discount_amount'] ?? 0.00,
                    'grand_total'       => $item['grand_total'],
                ]);

                $qty = $purchase->invoice_type === 'purchase' ? $item['quantity'] : -$item['quantity'];
                $unitName = $itemUnit->unit->name ?? 'حبة';
                $unitFactor = (float) $itemUnit->conversion_factor;

                // 1. محرك اعتماد سعر آخر شراء وتحديث كرت الصنف وكافة مصفوفة الوحدات والأسعار والتكلفة الأجنبية
                if ($purchase->invoice_type === 'purchase') {
                    $itemModel = Item::find($item['item_id']);
                    if ($itemModel) {
                        $this->syncItemCostsAndPrices($itemModel, $itemUnit, $item, $unitFactor);
                    }
                }

                // 2. تسجيل الحركة المخزنية وتحديث الـ Cache بمعرف المصفوفة
                $this->stockService->recordMovement(
                    $item['item_id'],
                    $purchase->store_id,
                    $itemUnit->id,
                    $purchase->invoice_type === 'purchase' ? 'purchase' : 'adjustment',
                    $purchase->invoice_number,
                    $unitName,
                    $qty,
                    $unitFactor,
                    $item['unit_cost'],
                    $purchase->notes
                );
            }

            // توليد القيد المالي وربط الـ ID الناتج بالفاتورة
            $journalEntry = $this->generateJournalEntry($purchase);
            $purchase->update(['journal_entry_id' => $journalEntry->id]);

            return $purchase->load([
                'items.purchase',
                'items.item.stocks',
                'items.item.units.unit',
                'items.itemUnit.unit',
                'currency'
            ]);
        });
    }

    /**
     * معالجة تعديل وتحديث فاتورة قائمة بشكل فوري وآمن مع دعم التكلفة الأجنبية
     */
    public function updatePurchase(Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $data) {

            $this->stockService->clearDocumentMovements($purchase->invoice_number);

            // الاعتماد على الرابط المباشر لحذف القيد القديم ومنع تعارض القيود الفريدة بسبب الـ SoftDeletes
            if ($purchase->journal_entry_id) {
                $oldEntry = JournalEntry::find($purchase->journal_entry_id);
                if ($oldEntry) {
                    $this->journalService->deleteEntry($oldEntry);
                    $oldEntry->forceDelete();
                }
            }

            $items = $data['items'];
            unset($data['items']);
            $purchase->update($data);

            $purchase->items()->delete();

            foreach ($items as $item) {
                // جلب سطر الوحدة المحدد من مصفوفة الأصناف
                $itemUnit = ItemUnit::with('unit')
                    ->where('item_id', $item['item_id'])
                    ->where('id', $item['item_unit_id'])
                    ->firstOrFail();

                $purchaseItem = $purchase->items()->create([
                    'item_id'           => $item['item_id'],
                    'item_unit_id'      => $itemUnit->id,
                    'quantity'          => $item['quantity'],
                    'unit_cost'         => $item['unit_cost'],
                    'foreign_unit_cost' => $item['foreign_unit_cost'] ?? null,
                    'profit_margin'     => $item['profit_margin'] ?? 0.00,
                    'selling_price'     => $item['selling_price'] ?? null,
                    'expiry_date'       => $item['expiry_date'] ?? null,
                    'subtotal'          => $item['subtotal'],
                    'discount_amount'   => $item['discount_amount'] ?? 0.00,
                    'grand_total'       => $item['grand_total'],
                ]);

                $qty = $purchase->invoice_type === 'purchase' ? $item['quantity'] : -$item['quantity'];
                $unitName = $itemUnit->unit->name ?? 'حبة';
                $unitFactor = (float) $itemUnit->conversion_factor;

                // 1. محرك اعتماد سعر آخر شراء وتحديث كرت الصنف وكافة مصفوفة الوحدات والأسعار والتكلفة الأجنبية
                if ($purchase->invoice_type === 'purchase') {
                    $itemModel = Item::find($item['item_id']);
                    if ($itemModel) {
                        $this->syncItemCostsAndPrices($itemModel, $itemUnit, $item, $unitFactor);
                    }
                }

                // 2. تسجيل الحركة الجديدة
                $this->stockService->recordMovement(
                    $item['item_id'],
                    $purchase->store_id,
                    $itemUnit->id,
                    $purchase->invoice_type === 'purchase' ? 'purchase' : 'adjustment',
                    $purchase->invoice_number,
                    $unitName,
                    $qty,
                    $unitFactor,
                    $item['unit_cost'],
                    $purchase->notes
                );
            }

            // إعادة توليد القيد وتحديث الفاتورة بالمعرف الجديد للربط المالي الصارم
            $newEntry = $this->generateJournalEntry($purchase);
            $purchase->update(['journal_entry_id' => $newEntry->id]);

            return $purchase->load([
                'items.purchase',
                'items.item.stocks',
                'items.item.units.unit',
                'items.itemUnit.unit',
                'currency'
            ]);
        });
    }

    /**
     * حذف أرشيفي للفاتورة وعكس الأثر اللوجستي والمالي بالكامل
     */
    public function deletePurchase(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $this->stockService->clearDocumentMovements($purchase->invoice_number);

            if ($purchase->journal_entry_id) {
                $oldEntry = JournalEntry::find($purchase->journal_entry_id);
                if ($oldEntry) {
                    $this->journalService->deleteEntry($oldEntry);
                }
            }

            $purchase->delete();
        });
    }

    /**
     * مزامنة تكاليف الوحدات وسعر الصنف والتكلفة الأجنبية وفق معيار سعر آخر شراء وتحديث الأسعار التلقائية
     */
    protected function syncItemCostsAndPrices(Item $itemModel, ItemUnit $purchasedUnit, array $itemData, float $unitFactor): void
    {
        // 1. تحديث نسبة الربح وتاريخ الصلاحية في كرت الصنف الأساسي إن وجدا
        $itemUpdateData = [];

        if (isset($itemData['profit_margin']) && !is_null($itemData['profit_margin'])) {
            $itemUpdateData['profit_margin'] = (float) $itemData['profit_margin'];
        }

        if (isset($itemData['expiry_date']) && !empty($itemData['expiry_date'])) {
            $itemUpdateData['expiry_date'] = $itemData['expiry_date'];
        }

        if (!empty($itemUpdateData)) {
            $itemModel->update($itemUpdateData);
        }

        // 2. حساب تكلفة الوحدة الصغرى القياسية وفق سعر آخر شراء بالعملة المحلية والأجنبية
        $unitFactor = (float) ($unitFactor > 0 ? $unitFactor : 1.00);
        $newBaseCost = (float) $itemData['unit_cost'] / $unitFactor;

        $hasForeignCost = isset($itemData['foreign_unit_cost']) && !is_null($itemData['foreign_unit_cost']) && (float) $itemData['foreign_unit_cost'] > 0;
        $newBaseForeignCost = $hasForeignCost ? ((float) $itemData['foreign_unit_cost'] / $unitFactor) : null;

        // 3. جلب كافة وحدات الصنف ومزامنة تكلفتها بناءً على معامل تحويل كل وحدة
        $allUnits = ItemUnit::with('prices')->where('item_id', $itemModel->id)->get();
        $margin = (float) ($itemModel->profit_margin ?? 0);
        $pricingPolicy = $itemModel->pricing_policy ?? 'manual';
        $roundingRule = $itemModel->rounding_rule ?? 'none';

        foreach ($allUnits as $unit) {
            $factor = (float) ($unit->conversion_factor > 0 ? $unit->conversion_factor : 1.00);
            $calculatedCost = round($newBaseCost * $factor, 4);
            $calculatedForeignCost = $newBaseForeignCost !== null ? round($newBaseForeignCost * $factor, 4) : $unit->foreign_cost;

            $unitUpdates = [
                'cost'         => $calculatedCost,
                'foreign_cost' => $calculatedForeignCost,
            ];

            // إذا كانت هذه الوحدة هي المشتراة وتم إدخال سعر بيع صريح لها
            if ($unit->id === $purchasedUnit->id && isset($itemData['selling_price']) && !is_null($itemData['selling_price'])) {
                $unitUpdates['price'] = (float) $itemData['selling_price'];
            } elseif ($pricingPolicy === 'auto_indexed' && $margin > 0) {
                // احتساب سعر البيع تلقائياً إذا كانت السياسة auto_indexed
                $rawPrice = $calculatedCost * (1 + ($margin / 100));
                $unitUpdates['price'] = $this->applyRounding($rawPrice, $roundingRule);
            }

            $unit->update($unitUpdates);

            // تحديث فئات الأسعار التابعة للوحدة في حال التسعير التلقائي
            if ($pricingPolicy === 'auto_indexed' && isset($unitUpdates['price']) && $unit->prices->isNotEmpty()) {
                foreach ($unit->prices as $priceRow) {
                    $discount = (float) ($priceRow->discount_percentage ?? 0);
                    $discountedPrice = $unitUpdates['price'] * (1 - ($discount / 100));
                    $priceRow->update([
                        'price' => $this->applyRounding($discountedPrice, $roundingRule),
                    ]);
                }
            }
        }
    }

    /**
     * تطبيق قواعد التقريب السعري المعتمدة في النظام
     */
    private function applyRounding(float $price, string $rule = 'none'): float
    {
        if ($price <= 0) {
            return 0.00;
        }

        switch ($rule) {
            case 'nearest_50':
                return round($price / 50) * 50;
            case 'nearest_100':
                return round($price / 100) * 100;
            case 'nearest_500':
                return round($price / 500) * 500;
            case 'psychological_90':
                $base = 100;
                $ending = 90;
                if ($price <= $ending) {
                    return (float) $ending;
                }
                $remainder = fmod($price, $base);
                $baseFloor = floor($price / base) * $base;
                return (float) ($remainder <= $ending ? $baseFloor + $ending : $baseFloor + $base + $ending);
            case 'psychological_900':
                $base = 1000;
                $ending = 900;
                if ($price <= $ending) {
                    return (float) $ending;
                }
                $remainder = fmod($price, $base);
                $baseFloor = floor($price / base) * $base;
                return (float) ($remainder <= $ending ? $baseFloor + $ending : $baseFloor + $base + $ending);
            default:
                return round($price, 2);
        }
    }

    /**
     * توليد القيد المحاسبي المتوافق مع حسابات الشجرة التجميعية الثابتة والـ Sub-ledgers
     */
    private function generateJournalEntry(Purchase $purchase): JournalEntry
    {
        $lines = [];

        $inventoryAccount = Account::where('code', Account::CODE_INVENTORY)->firstOrFail();
        $treasuryAccount  = Account::where('code', Account::CODE_TREASURY)->firstOrFail();
        $bankAccount      = Account::where('code', Account::CODE_BANKS)->firstOrFail();
        $supplierAccount  = Account::where('code', Account::CODE_SUPPLIERS)->firstOrFail();

        $financialAccountId = null;
        $financialSubLedgerType = null;
        $financialSubLedgerId = null;
        $paymentLabel = '';

        if ($purchase->payment_type === 'cash') {
            $financialAccountId = $treasuryAccount->id;
            $financialSubLedgerType = Treasury::class;
            $financialSubLedgerId = $purchase->treasury_id;
            $paymentLabel = 'صرف نقدي من خزينة بموجب فاتورة مشتريات رقم: ';
        } elseif ($purchase->payment_type === 'card') {
            $financialAccountId = $bankAccount->id;
            $financialSubLedgerType = Bank::class;
            $financialSubLedgerId = $purchase->bank_id;
            $paymentLabel = 'صرف بنكي/شبكة بموجب فاتورة مشتريات رقم: ';
        } else {
            $financialAccountId = $supplierAccount->id;
            $financialSubLedgerType = Supplier::class;
            $financialSubLedgerId = $purchase->supplier_id;
            $paymentLabel = 'مستحقات المورد بموجب فاتورة مشتريات رقم: ';
        }

        if ($purchase->invoice_type === 'purchase') {
            $lines[] = [
                'account_id'      => $inventoryAccount->id,
                'sub_ledger_type' => Store::class,
                'sub_ledger_id'   => $purchase->store_id,
                'debit'           => $purchase->grand_total,
                'credit'          => 0.00,
                'line_notes'      => 'إثبات بضاعة واردة بموجب فاتورة مشتريات رقم: ' . $purchase->invoice_number,
            ];

            $lines[] = [
                'account_id'      => $financialAccountId,
                'sub_ledger_type' => $financialSubLedgerType,
                'sub_ledger_id'   => $financialSubLedgerId,
                'debit'           => 0.00,
                'credit'          => $purchase->grand_total,
                'line_notes'      => $paymentLabel . $purchase->invoice_number,
            ];
        } else {
            $lines[] = [
                'account_id'      => $financialAccountId,
                'sub_ledger_type' => $financialSubLedgerType,
                'sub_ledger_id'   => $financialSubLedgerId,
                'debit'           => $purchase->grand_total,
                'credit'          => 0.00,
                'line_notes'      => 'تسوية استرداد مالي/تخفيض التزام بموجب مرتجع مشتريات رقم: ' . $purchase->invoice_number,
            ];

            $lines[] = [
                'account_id'      => $inventoryAccount->id,
                'sub_ledger_type' => Store::class,
                'sub_ledger_id'   => $purchase->store_id,
                'debit'           => 0.00,
                'credit'          => $purchase->grand_total,
                'line_notes'      => 'خروج بضاعة بموجب مرتجع مشتريات رقم: ' . $purchase->invoice_number,
            ];
        }

        return $this->journalService->createEntry([
            'entry_number' => $purchase->invoice_number,
            'entry_date'   => Carbon::parse($purchase->invoice_date)->format('Y-m-d'),
            'type'         => 'journal',
            'notes'        => $purchase->notes ?? 'قيد تلقائي ناتج عن نظام اللوجستيات والمشتريات المطور',
            'user_id'      => $purchase->user_id,
            'lines'        => $lines
        ]);
    }
}