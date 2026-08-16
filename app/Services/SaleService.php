<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\JournalEntry;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\ItemStock;
use App\Models\Treasury;
use App\Models\Bank;
use App\Models\Store;
use App\Models\ItemComponent;
use App\Services\StockMovementService;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class SaleService
{
    protected $stockService;
    protected $journalService;

    public function __construct(StockMovementService $stockService, JournalEntryService $journalService)
    {
        $this->stockService = $stockService;
        $this->journalService = $journalService;
    }

    /**
     * جلب فواتير المبيعات ممررة عبر الـ Pagination مع تطبيق الفلاتر المتقدمة ونطاق التاريخ
     */
    public function getPaginatedSales(array $filters = [], int $perPage = 15)
    {
        $query = Sale::with(['store', 'customer', 'user', 'items.item', 'items.itemUnit.unit', 'treasury', 'bank']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['invoice_type'])) {
            $query->where('invoice_type', $filters['invoice_type']);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['from_date'])) {
            $query->whereDate('invoice_date', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate('invoice_date', '<=', $filters['to_date']);
        }

        return $query->latest('invoice_date')->paginate($perPage);
    }

/**
     * معالجة وحفظ فاتورة مبيعات أو مرتجع جديدة بالكامل وتفكيك الأصناف التجميعية مخزنياً
     */
public function createSale(array $data, int $userId): Sale
{
    return DB::transaction(function () use ($data, $userId) {
        $saleData = array_merge($data, ['user_id' => $userId]);
        $items = $saleData['items'];

        // فحص المخزون قبل إنشاء الفاتورة وحجز الكميات
        $this->validateStockAvailability(
            $items,
            (int) $saleData['store_id'],
            $saleData['invoice_type'] ?? 'sale',
            $userId
        );

        unset($saleData['items']);
        $sale = Sale::create($saleData);

        foreach ($items as $item) {
            $itemUnit = ItemUnit::with(['unit', 'item'])
                ->where('item_id', $item['item_id'])
                ->where('id', $item['item_unit_id'])
                ->firstOrFail();

            // حفظ سطر الفاتورة
            $saleItem = $sale->items()->create([
                'item_id'         => $item['item_id'],
                'item_unit_id'    => $itemUnit->id,
                'quantity'        => $item['quantity'],
                'unit_price'      => $item['unit_price'],
                'price_type'      => $item['price_type'] ?? 'default',
                'subtotal'        => $item['subtotal'],
                'discount_amount' => $item['discount_amount'] ?? 0.00,
                'grand_total'     => $item['grand_total'],
            ]);

            $quantity = (float) $item['quantity'];
            $unitFactor = (float) $itemUnit->conversion_factor;

            // الفحص والفك الشرطي للأصناف التجميعية بناءً على الكمية المباعة المباشرة
            if ($itemUnit->item && $itemUnit->item->is_composite) {
                $components = ItemComponent::where('parent_item_id', $itemUnit->item_id)->get();

                foreach ($components as $component) {
                    $childItem = Item::find($component->child_item_id);
                    if (!$childItem) continue;

                    $childBaseUnit = ItemUnit::with('unit')
                        ->where('item_id', $childItem->id)
                        ->where('unit_id', $childItem->base_unit_id)
                        ->first();

                    if (!$childBaseUnit) continue;

                    $componentQtyCalculated = $quantity * $unitFactor * (float) $component->quantity;
                    $qty = $sale->invoice_type === 'sale' ? -$componentQtyCalculated : $componentQtyCalculated;
                    $childUnitName = $childBaseUnit->unit->name ?? 'حبة';

                    $this->stockService->recordMovement(
                        $childItem->id,
                        $sale->store_id,
                        $childBaseUnit->id,
                        $sale->invoice_type === 'sale' ? 'sales' : 'adjustment',
                        $sale->invoice_number,
                        $childUnitName,
                        $qty,
                        1.0,
                        (float) $childBaseUnit->cost,
                        $sale->notes
                    );
                }
            } else {
                // الأصناف العادية: خصم/إضافة الكمية الصريحة مباشرة
                $qty = $sale->invoice_type === 'sale' ? -$quantity : $quantity;
                $unitName = $itemUnit->unit->name ?? 'حبة';

                $this->stockService->recordMovement(
                    $item['item_id'],
                    $sale->store_id,
                    $itemUnit->id,
                    $sale->invoice_type === 'sale' ? 'sales' : 'adjustment',
                    $sale->invoice_number,
                    $unitName,
                    $qty,
                    $unitFactor,
                    $item['unit_price'],
                    $sale->notes
                );
            }
        }

        $journalEntry = $this->generateJournalEntry($sale);
        $sale->update(['journal_entry_id' => $journalEntry->id]);

        return $sale->load('items.itemUnit.unit');
    });
}

   /**
     * معالجة تعديل وتحديث فاتورة مبيعات قائمة
     */
   public function updateSale(Sale $sale, array $data): Sale
   {
       return DB::transaction(function () use ($sale, $data) {
           // 1. إعادة ترصيد المخزون القديم لحساب الرصيد الحقيقي قبل التحقق
           $this->stockService->clearDocumentMovements($sale->invoice_number);

           if ($sale->journal_entry_id) {
               $oldEntry = JournalEntry::find($sale->journal_entry_id);
               if ($oldEntry) {
                   $this->journalService->deleteEntry($oldEntry);
                   $oldEntry->forceDelete();
               }
           }

           $items = $data['items'];
           $targetStoreId = (int) ($data['store_id'] ?? $sale->store_id);
           $invoiceType = $data['invoice_type'] ?? $sale->invoice_type;

           // 2. فحص المخزون المتاح بعد تفريغ الحركات القديمة
           $this->validateStockAvailability(
               $items,
               $targetStoreId,
               $invoiceType,
               $sale->user_id
           );

           unset($data['items']);
           $sale->update($data);

           $sale->items()->delete();

           foreach ($items as $item) {
               $itemUnit = ItemUnit::with(['unit', 'item'])
                   ->where('item_id', $item['item_id'])
                   ->where('id', $item['item_unit_id'])
                   ->firstOrFail();

               $saleItem = $sale->items()->create([
                   'item_id'         => $item['item_id'],
                   'item_unit_id'    => $itemUnit->id,
                   'quantity'        => $item['quantity'],
                   'unit_price'      => $item['unit_price'],
                   'price_type'      => $item['price_type'] ?? 'default',
                   'subtotal'        => $item['subtotal'],
                   'discount_amount' => $item['discount_amount'] ?? 0.00,
                   'grand_total'     => $item['grand_total'],
               ]);

               $quantity = (float) $item['quantity'];
               $unitFactor = (float) $itemUnit->conversion_factor;

               if ($itemUnit->item && $itemUnit->item->is_composite) {
                   $components = ItemComponent::where('parent_item_id', $itemUnit->item_id)->get();

                   foreach ($components as $component) {
                       $childItem = Item::find($component->child_item_id);
                       if (!$childItem) continue;

                       $childBaseUnit = ItemUnit::with('unit')
                           ->where('item_id', $childItem->id)
                           ->where('unit_id', $childItem->base_unit_id)
                           ->first();

                       if (!$childBaseUnit) continue;

                       $componentQtyCalculated = $quantity * $unitFactor * (float) $component->quantity;
                       $qty = $sale->invoice_type === 'sale' ? -$componentQtyCalculated : $componentQtyCalculated;
                       $childUnitName = $childBaseUnit->unit->name ?? 'حبة';

                       $this->stockService->recordMovement(
                           $childItem->id,
                           $sale->store_id,
                           $childBaseUnit->id,
                           $sale->invoice_type === 'sale' ? 'sales' : 'adjustment',
                           $sale->invoice_number,
                           $childUnitName,
                           $qty,
                           1.0,
                           (float) $childBaseUnit->cost,
                           $sale->notes
                       );
                   }
               } else {
                   $qty = $sale->invoice_type === 'sale' ? -$quantity : $quantity;
                   $unitName = $itemUnit->unit->name ?? 'حبة';

                   $this->stockService->recordMovement(
                       $item['item_id'],
                       $sale->store_id,
                       $itemUnit->id,
                       $sale->invoice_type === 'sale' ? 'sales' : 'adjustment',
                       $sale->invoice_number,
                       $unitName,
                       $qty,
                       $unitFactor,
                       $item['unit_price'],
                       $sale->notes
                   );
               }
           }

           $newEntry = $this->generateJournalEntry($sale);
           $sale->update(['journal_entry_id' => $newEntry->id]);

           return $sale->load('items.itemUnit.unit');
       });
   }

    /**
     * حذف أرشيفي لفاتورة المبيعات مع تصفية أثرها المخزني والمالي
     */
    public function deleteSale(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $this->stockService->clearDocumentMovements($sale->invoice_number);

            if ($sale->journal_entry_id) {
                $oldEntry = JournalEntry::find($sale->journal_entry_id);
                if ($oldEntry) {
                    $this->journalService->deleteEntry($oldEntry);
                }
            }

            $sale->delete();
        });
    }

    /**
     * توليد القيد المحاسبي المزدوج وتصعيد حساب تكلفة المكونات المباعة بدقة
     */
    private function generateJournalEntry(Sale $sale): JournalEntry
    {
        $lines = [];

        $treasuryAccount  = Account::where('code', Account::CODE_TREASURY)->firstOrFail();
        $bankAccount      = Account::where('code', Account::CODE_BANKS)->firstOrFail();
        $customerAccount  = Account::where('code', Account::CODE_CUSTOMERS)->firstOrFail();
        $incomeAccount    = Account::where('code', Account::CODE_INCOME)->firstOrFail();
        $inventoryAccount = Account::where('code', Account::CODE_INVENTORY)->firstOrFail();
        $cogsAccount      = Account::where('code', Account::CODE_COGS)->firstOrFail();

        $totalInvoiceCost = 0.00;
        foreach ($sale->items()->with(['item', 'itemUnit'])->get() as $saleItem) {
            $unitFactor = (float) ($saleItem->itemUnit->conversion_factor ?? 1.00);
            $costQuantity = (float) $saleItem->quantity;
            $baseQuantity = $costQuantity * $unitFactor;

            if ($saleItem->item && $saleItem->item->is_composite) {
                $components = ItemComponent::where('parent_item_id', $saleItem->item_id)->get();

                foreach ($components as $component) {
                    $childItem = Item::find($component->child_item_id);
                    if (!$childItem) continue;

                    $childBaseUnit = ItemUnit::where('item_id', $childItem->id)
                        ->where('unit_id', $childItem->base_unit_id)
                        ->first();

                    $childCost = $childBaseUnit ? (float) $childBaseUnit->cost : 0.00;
                    $childBaseQtyConsumed = $baseQuantity * (float) $component->quantity;
                    $totalInvoiceCost += ($childBaseQtyConsumed * $childCost);
                }
            } else {
                $baseUnitRow = ItemUnit::where('item_id', $saleItem->item_id)
                    ->where('unit_id', $saleItem->item->base_unit_id)
                    ->first();

                $itemUnitCost = $baseUnitRow ? (float) $baseUnitRow->cost : 0.00;
                $totalInvoiceCost += ($baseQuantity * $itemUnitCost);
            }
        }

        if ($sale->invoice_type === 'sale') {
            if ($sale->payment_type === 'cash') {
                $lines[] = [
                    'account_id'      => $treasuryAccount->id,
                    'sub_ledger_type' => Treasury::class,
                    'sub_ledger_id'   => $sale->treasury_id,
                    'debit'           => $sale->grand_total,
                    'credit'          => 0.00,
                    'line_notes'      => 'تحصيل نقدي لقيمة مبيعات بموجب فاتورة رقم: ' . $sale->invoice_number,
                ];
            } elseif ($sale->payment_type === 'card') {
                $lines[] = [
                    'account_id'      => $bankAccount->id,
                    'sub_ledger_type' => Bank::class,
                    'sub_ledger_id'   => $sale->bank_id,
                    'debit'           => $sale->grand_total,
                    'credit'          => 0.00,
                    'line_notes'      => 'تحصيل بنكي/شبكة لقيمة مبيعات بموجب فاتورة رقم: ' . $sale->invoice_number,
                ];
            } else {
                $lines[] = [
                    'account_id'      => $customerAccount->id,
                    'sub_ledger_type' => Customer::class,
                    'sub_ledger_id'   => $sale->customer_id,
                    'debit'           => $sale->grand_total,
                    'credit'          => 0.00,
                    'line_notes'      => 'مديونية عميل بموجب فاتورة مبيعات رقم: ' . $sale->invoice_number,
                ];
            }

            $lines[] = [
                'account_id'      => $incomeAccount->id,
                'sub_ledger_type' => null,
                'sub_ledger_id'   => null,
                'debit'           => 0.00,
                'credit'          => $sale->grand_total,
                'line_notes'      => 'إثبات إيراد مبيعات بموجب فاتورة رقم: ' . $sale->invoice_number,
            ];

            if ($totalInvoiceCost > 0) {
                $lines[] = [
                    'account_id'      => $cogsAccount->id,
                    'sub_ledger_type' => null,
                    'sub_ledger_id'   => null,
                    'debit'           => $totalInvoiceCost,
                    'credit'          => 0.00,
                    'line_notes'      => 'إثبات تكلفة البضاعة المباعة التلقائي لفاتورة رقم: ' . $sale->invoice_number,
                ];
                $lines[] = [
                    'account_id'      => $inventoryAccount->id,
                    'sub_ledger_type' => Store::class,
                    'sub_ledger_id'   => $sale->store_id,
                    'debit'           => 0.00,
                    'credit'          => $totalInvoiceCost,
                    'line_notes'      => 'تخفيض قيمة المستودعات بالبضاعة المباعة بموجب فاتورة رقم: ' . $sale->invoice_number,
                ];
            }
        } else {
            $lines[] = [
                'account_id'      => $incomeAccount->id,
                'sub_ledger_type' => null,
                'sub_ledger_id'   => null,
                'debit'           => $sale->grand_total,
                'credit'          => 0.00,
                'line_notes'      => 'تخفيض إيرادات بموجب مرتجع مبيعات رقم: ' . $sale->invoice_number,
            ];

            if ($sale->payment_type === 'cash') {
                $lines[] = [
                    'account_id'      => $treasuryAccount->id,
                    'sub_ledger_type' => Treasury::class,
                    'sub_ledger_id'   => $sale->treasury_id,
                    'debit'           => 0.00,
                    'credit'          => $sale->grand_total,
                    'line_notes'      => 'رد مبلغ نقدي لعميل بموجب مرتجع رقم: ' . $sale->invoice_number,
                ];
            } elseif ($sale->payment_type === 'card') {
                $lines[] = [
                    'account_id'      => $bankAccount->id,
                    'sub_ledger_type' => Bank::class,
                    'sub_ledger_id'   => $sale->bank_id,
                    'debit'           => 0.00,
                    'credit'          => $sale->grand_total,
                    'line_notes'      => 'رد عبر الحساب البنكي/الشبكة لعميل بموجب مرتجع رقم: ' . $sale->invoice_number,
                ];
            } else {
                $lines[] = [
                    'account_id'      => $customerAccount->id,
                    'sub_ledger_type' => Customer::class,
                    'sub_ledger_id'   => $sale->customer_id,
                    'debit'           => 0.00,
                    'credit'          => $sale->grand_total,
                    'line_notes'      => 'تخفيض مديونية عميل بموجب مرتجع مبيعات رقم: ' . $sale->invoice_number,
                ];
            }

            if ($totalInvoiceCost > 0) {
                $lines[] = [
                    'account_id'      => $inventoryAccount->id,
                    'sub_ledger_type' => Store::class,
                    'sub_ledger_id'   => $sale->store_id,
                    'debit'           => $totalInvoiceCost,
                    'credit'          => 0.00,
                    'line_notes'      => 'إعادة إدخال قيمة البضاعة المرتجعة للمستودعات بموجب مستند رقم: ' . $sale->invoice_number,
                ];
                $lines[] = [
                    'account_id'      => $cogsAccount->id,
                    'sub_ledger_type' => null,
                    'sub_ledger_id'   => null,
                    'debit'           => 0.00,
                    'credit'          => $totalInvoiceCost,
                    'line_notes'      => 'تخفيض مصروف تكلفة البضاعة المباعة بالمرتجع رقم: ' . $sale->invoice_number,
                ];
            }
        }

        return $this->journalService->createEntry([
            'entry_number' => $sale->invoice_number,
            'entry_date'   => Carbon::parse($sale->invoice_date)->format('Y-m-d'),
            'type'         => 'journal',
            'notes'        => $sale->notes ?? 'قيد تلقائي مزدوج ناتج عن حركة مبيعات كاشير المحدثة',
            'user_id'      => $sale->user_id,
            'lines'        => $lines
        ]);
    }

    /**
     * التبديل الذري للصنف مع تفكيك المكونات التجميعية
     */
    public function swapRawMaterials(Sale $sale, array $itemsData, string $productionStatus): Sale
    {
        return DB::transaction(function () use ($sale, $itemsData, $productionStatus) {
            $this->stockService->clearDocumentMovements($sale->invoice_number);

            if ($sale->journal_entry_id) {
                $oldEntry = JournalEntry::find($sale->journal_entry_id);
                if ($oldEntry) {
                    $this->journalService->deleteEntry($oldEntry);
                    $oldEntry->forceDelete();
                }
            }

            foreach ($itemsData as $swapItem) {
                $saleItem = $sale->items()->find($swapItem['sale_item_id']);

                if ($saleItem) {
                    $oldItemUnit = DB::table('item_units')->where('id', $saleItem->item_unit_id)->first();

                    if ($oldItemUnit) {
                        $newItemUnit = DB::table('item_units')
                            ->where('item_id', $swapItem['item_id'])
                            ->where('unit_id', $oldItemUnit->unit_id)
                            ->first();

                        if ($newItemUnit) {
                            $saleItem->update([
                                'item_id'      => $swapItem['item_id'],
                                'item_unit_id' => $newItemUnit->id
                            ]);
                        } else {
                            $fallbackUnit = DB::table('item_units')->where('item_id', $swapItem['item_id'])->first();
                            $saleItem->update([
                                'item_id'      => $swapItem['item_id'],
                                'item_unit_id' => $fallbackUnit?->id ?? $saleItem->item_unit_id
                            ]);
                        }
                    }
                }
            }

            foreach ($sale->items()->with(['itemUnit.unit', 'item'])->get() as $saleItem) {
                $quantity = (float) $saleItem->quantity;
                $unitFactor = (float) $saleItem->itemUnit->conversion_factor;

                if ($saleItem->item && $saleItem->item->is_composite) {
                    $components = ItemComponent::where('parent_item_id', $saleItem->item_id)->get();

                    foreach ($components as $component) {
                        $childItem = Item::find($component->child_item_id);
                        if (!$childItem) continue;

                        $childBaseUnit = ItemUnit::with('unit')
                            ->where('item_id', $childItem->id)
                            ->where('unit_id', $childItem->base_unit_id)
                            ->first();

                        if (!$childBaseUnit) continue;

                        $componentQtyCalculated = $quantity * $unitFactor * (float) $component->quantity;
                        $qty = $sale->invoice_type === 'sale' ? -$componentQtyCalculated : $componentQtyCalculated;
                        $childUnitName = $childBaseUnit->unit->name ?? 'حبة';

                        $this->stockService->recordMovement(
                            $childItem->id,
                            $sale->store_id,
                            $childBaseUnit->id,
                            $sale->invoice_type === 'sale' ? 'sales' : 'adjustment',
                            $sale->invoice_number,
                            $childUnitName,
                            $qty,
                            1.0,
                            (float) $childBaseUnit->cost,
                            $sale->notes
                        );
                    }
                } else {
                    $qty = $sale->invoice_type === 'sale' ? -$quantity : $quantity;
                    $unitName = $saleItem->itemUnit->unit->name ?? 'حبة';

                    $this->stockService->recordMovement(
                        $saleItem->item_id,
                        $sale->store_id,
                        $saleItem->item_unit_id,
                        $sale->invoice_type === 'sale' ? 'sales' : 'adjustment',
                        $sale->invoice_number,
                        $unitName,
                        $qty,
                        $unitFactor,
                        $saleItem->unit_price,
                        $sale->notes
                    );
                }
            }

            $newEntry = $this->generateJournalEntry($sale);

            $sale->update([
                'journal_entry_id'  => $newEntry->id,
                'production_status' => $productionStatus
            ]);

            return $sale->load('items.itemUnit.unit');
        });
    }




    /**
     * التحقق الصارم من توفر الرصيد المخزني للأصناف قبل إتمام عملية البيع عند تفعيل خيار التحكم بالمخزون
     *
     * @throws Exception
     */
    protected function validateStockAvailability(array $items, int $storeId, string $invoiceType, int $userId): void
    {
        // التحقق ينطبق فقط على فواتير المبيعات الصادرة
        if ($invoiceType !== 'sale') {
            return;
        }

        $user = \App\Models\User::find($userId);
        if (!$user || !$user->stock_control) {
            return;
        }

        // تجميع إجمالي الكميات المطلوبة بالوحدة الصغرى لكل صنف (لمنع التحايل بتكرار الصنف في أكثر من سطر)
        $requiredQuantities = [];

        foreach ($items as $itemData) {
            $itemUnit = ItemUnit::with(['unit', 'item'])
                ->where('item_id', $itemData['item_id'])
                ->where('id', $itemData['item_unit_id'])
                ->firstOrFail();

            $quantity = (float) $itemData['quantity'];
            $unitFactor = (float) ($itemUnit->conversion_factor ?? 1.0);
            $baseQuantity = $quantity * $unitFactor;

            // تفكيك الأصناف التجميعية وفحص أرصدة خاماتها ومكوناتها الأساسية
            if ($itemUnit->item && $itemUnit->item->is_composite) {
                $components = ItemComponent::where('parent_item_id', $itemUnit->item_id)->get();

                foreach ($components as $component) {
                    $childItem = Item::find($component->child_item_id);
                    if (!$childItem) continue;

                    $childRequiredQty = $baseQuantity * (float) $component->quantity;

                    if (!isset($requiredQuantities[$childItem->id])) {
                        $requiredQuantities[$childItem->id] = [
                            'name' => $childItem->name,
                            'qty'  => 0.0,
                        ];
                    }
                    $requiredQuantities[$childItem->id]['qty'] += $childRequiredQty;
                }
            } else {
                $itemId = (int) $itemData['item_id'];
                $itemName = $itemUnit->item->name ?? "صنف رقم {$itemId}";

                if (!isset($requiredQuantities[$itemId])) {
                    $requiredQuantities[$itemId] = [
                        'name' => $itemName,
                        'qty'  => 0.0,
                    ];
                }
                $requiredQuantities[$itemId]['qty'] += $baseQuantity;
            }
        }

        // فحص مطابقة الكمية المطلوبة مع الرصيد اللحظي الفعلي في المستودع
        foreach ($requiredQuantities as $itemId => $demand) {
            $currentStock = (float) (ItemStock::where('item_id', $itemId)
                ->where('store_id', $storeId)
                ->value('current_quantity') ?? 0.0);

            if ($currentStock < $demand['qty']) {
                throw new Exception(
                    "عفواً، لا يمكن إتمام عملية البيع لعدم توفر رصيد كافٍ في المخزن للصنف: [ {$demand['name']} ]. " .
                    "الرصيد المتوفر حالياً: ({$currentStock})، والكمية المطلوبة: ({$demand['qty']})."
                );
            }
        }
    }
}
