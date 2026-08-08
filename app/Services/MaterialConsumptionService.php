<?php

namespace App\Services;

use App\Models\MaterialConsumption;
use App\Models\JournalEntry;
use App\Models\Account;
use App\Models\Store;
use App\Models\ItemUnit;
use App\Services\StockMovementService;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class MaterialConsumptionService
{
    protected $stockService;
    protected $journalService;

    public function __construct(StockMovementService $stockService, JournalEntryService $journalService)
    {
        $this->stockService = $stockService;
        $this->journalService = $journalService;
    }

    /**
     * معالجة وحفظ حركة استهلاك خامة فردية للفني وتحديث الأرصدة والقيود برمجياً
     */
    public function createConsumption(array $data, int $userId): MaterialConsumption
    {
        return DB::transaction(function () use ($data, $userId) {
            // 1. إدراج الحركة في جدول الاستهلاك الفلات الجديد
            $consumptionData = array_merge($data, ['user_id' => $userId]);
            $consumption = MaterialConsumption::create($consumptionData);

            // 2. جلب تفاصيل وحدة الصنف الخام المختار لاستخراج معامل التحويل
            $itemUnit = ItemUnit::with(['unit', 'item'])
                ->where('item_id', $consumption->item_id)
                ->where('id', $consumption->item_unit_id)
                ->firstOrFail();

            $unitName = $itemUnit->unit->name ?? 'حبة';
            $unitFactor = (float) $itemUnit->conversion_factor;

            // [تأمين هندسي]: جلب التكلفة من سطر وحدة القاعدة المعتمدة للصنف منعاً للقيم الصفرية تماشياً مع معماريتك
            $baseUnitRow = ItemUnit::where('item_id', $consumption->item_id)
                ->where('unit_id', $itemUnit->item->base_unit_id)
                ->first();

            $baseCostPrice = $baseUnitRow ? (float) $baseUnitRow->cost : 0.00;

            // توليد رقم مستند مميز للحركة لتتبعها مخزنياً ومالياً
            $documentNumber = 'CON-' . str_pad($consumption->id, 5, '0', STR_PAD_LEFT);

            // 3. مناداة خدمة المخازن لاقتطاع الكمية المستهلكة بالسالب فوراً من الرصيد الحالي
            $negativeQty = -(float)$consumption->quantity;

            $this->stockService->recordMovement(
                $consumption->item_id,
                $consumption->store_id,
                $consumption->item_unit_id,
                'adjustment',
                $documentNumber,
                $unitName,
                $negativeQty,
                $unitFactor,
                $baseCostPrice,
                $consumption->notes
            );

            // [تأمين القيود]: احتساب إجمالي التكلفة الفعلي (الكمية المطلوبة × معامل التحويل × تكلفة وحدة القاعدة) لوزن القيد
            $totalBaseQuantity = (float)$consumption->quantity * $unitFactor;
            $finalCalculatedCost = $totalBaseQuantity * $baseCostPrice;

            // 4. توليد القيد المحاسبي المزدوج التلقائي لترحيل التكلفة وتحديث المستند بمعرف القيد
            if ($finalCalculatedCost > 0) {
                $journalEntry = $this->generateJournalEntry($consumption, $documentNumber, $finalCalculatedCost);
                $consumption->update(['journal_entry_id' => $journalEntry->id]);
            }

            return $consumption->load(['item', 'itemUnit.unit', 'store']);
        });
    }

    /**
     * الحذف الذري الآمن لإذن استهلاك الخامات وتطهير آثاره بالكامل من المخازن والمالية لضمان سلامة الجرد
     */
    public function deleteConsumption(MaterialConsumption $consumption): void
    {
        DB::transaction(function () use ($consumption) {
            $documentNumber = 'CON-' . str_pad($consumption->id, 5, '0', STR_PAD_LEFT);

            // 1. تطهير حركات جرد المخازن المرتبطة بهذا المستند فوراً لإعادة إرجاع الرصيد الفعلي على الرف
            $this->stockService->clearDocumentMovements($documentNumber);

            // 2. البحث عن القيد المالي المحاسبي وإعدامه وعكس أثره من شجرة الحسابات والدفاتر
            if ($consumption->journal_entry_id) {
                $oldEntry = JournalEntry::find($consumption->journal_entry_id);
                if ($oldEntry) {
                    $this->journalService->deleteEntry($oldEntry);
                    $oldEntry->forceDelete(); // الحذف الفعلي للسطر المالي لمنع الحركات المعلقة
                }
            }

            // 3. إجراء الحذف المؤرشف للمستند الأساسي في الورشة
            $consumption->delete();
        });
    }

    /**
     * توليد القيد المحاسبي التلقائي الموزون لحركة سحب الخامات
     */
    private function generateJournalEntry(MaterialConsumption $consumption, string $docNumber, float $totalCost): JournalEntry
    {
        $lines = [];

        $inventoryAccount = Account::where('code', Account::CODE_INVENTORY)->firstOrFail();
        $cogsAccount      = Account::where('code', Account::CODE_COGS)->firstOrFail();

        // السطر الأول: مدين لحساب تكلفة التشغيل (تحميل المصروف بالخامات المستهلكة فعلياً)
        $lines[] = [
            'account_id'      => $cogsAccount->id,
            'sub_ledger_type' => null,
            'sub_ledger_id'   => null,
            'debit'           => $totalCost,
            'credit'          => 0.00,
            'line_notes'      => 'تحميل تكلفة خامات مستهلكة بالورشة بموجب إذن صرف رقم: ' . $docNumber . ' - ' . ($consumption->notes ?? ''),
        ];

        // السطر الثاني: دائن لحساب المخزن (تخفيض قيمة المخزون الحالية بسعر التكلفة)
        $lines[] = [
            'account_id'      => $inventoryAccount->id,
            'sub_ledger_type' => Store::class,
            'sub_ledger_id'   => $consumption->store_id,
            'debit'           => 0.00,
            'credit'          => $totalCost,
            'line_notes'      => 'تخفيض المخزون بقيمة خامات منصرفة فنيّاً بموجب إذن رقم: ' . $docNumber,
        ];

        // إرسال مصفوفة الأسطر لخدمة القيود المركزية لتوليد وترحيل القيد آلياً لوزن الحسابات
        return $this->journalService->createEntry([
            'entry_number' => $docNumber,
            'entry_date'   => Carbon::now()->format('Y-m-d'),
            'type'         => 'journal',
            'notes'        => $consumption->notes ?? 'قيد تلقائي مزدوج ناتج عن إذن استهلاك خامات فردية بالورشة',
            'user_id'      => $consumption->user_id,
            'lines'        => $lines
        ]);
    }
}
