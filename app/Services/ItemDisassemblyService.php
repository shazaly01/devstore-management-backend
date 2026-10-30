<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemDisassembly;
use App\Models\ItemDisassemblyItem;
use App\Models\ItemUnit;
use App\Models\ItemStock;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Exception;

class ItemDisassemblyService
{
    protected StockMovementService $stockService;

    public function __construct(StockMovementService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * معالجة وحفظ إذن تفكيك جديد واحتساب تكاليف النواتج وتحديث المخازن
     */
    public function createDisassembly(array $data, int $userId): ItemDisassembly
    {
        return DB::transaction(function () use ($data, $userId) {
            $itemsData = $data['items'];
            unset($data['items']);

            // 1. جلب وحدة الصنف الخام لاستخراج معامل التحويل وسعر التكلفة المعتمد
            $sourceItemUnit = ItemUnit::with(['unit', 'item'])
                ->where('item_id', $data['source_item_id'])
                ->where('id', $data['source_item_unit_id'])
                ->firstOrFail();

            $sourceUnitFactor = (float) ($sourceItemUnit->conversion_factor > 0 ? $sourceItemUnit->conversion_factor : 1.00);
            $sourceQuantity = (float) $data['source_quantity'];
            $requiredBaseQuantity = $sourceQuantity * $sourceUnitFactor;

            // 2. التحقق من كفاية الرصيد الفعلي للصنف الخام في المستودع
            $sourceStock = ItemStock::where('item_id', $data['source_item_id'])
                ->where('store_id', $data['store_id'])
                ->first();

            $availableStock = $sourceStock ? (float) $sourceStock->current_quantity : 0.00;
            if ($availableStock < $requiredBaseQuantity) {
                $availableInSelectedUnit = $sourceUnitFactor > 0 ? round($availableStock / $sourceUnitFactor, 2) : 0;
                throw new Exception("الرصيد المتاح من الصنف الخام بالمستودع غير كافٍ. المطلوب: {$sourceQuantity}، المتاح حالياً: {$availableInSelectedUnit}");
            }

            // 3. تحديد تكلفة وحدة الصنف الخام (اعتماد القيمة المدخلة من المستخدم إن وجدت، أو احتسابها من كرت الوحدة)
            if (isset($data['source_unit_cost']) && (float) $data['source_unit_cost'] > 0) {
                $sourceUnitCost = (float) $data['source_unit_cost'];
            } else {
                $sourceBaseUnit = ItemUnit::where('item_id', $sourceItemUnit->item_id)
                    ->where('unit_id', $sourceItemUnit->item->base_unit_id)
                    ->first();

                $baseCost = $sourceBaseUnit ? (float) $sourceBaseUnit->cost : (float) $sourceItemUnit->cost;
                $sourceUnitCost = $baseCost * $sourceUnitFactor;
            }

            $sourceTotalCost = round($sourceQuantity * $sourceUnitCost, 4);

            // 4. إنشاء رأس مستند التفكيك
            $disassembly = ItemDisassembly::create([
                'disassembly_date'    => $data['disassembly_date'],
                'store_id'            => (int) $data['store_id'],
                'source_item_id'      => (int) $data['source_item_id'],
                'source_item_unit_id' => (int) $data['source_item_unit_id'],
                'source_quantity'     => $sourceQuantity,
                'source_unit_cost'    => $sourceUnitCost,
                'source_total_cost'   => $sourceTotalCost,
                'waste_quantity'      => (float) ($data['waste_quantity'] ?? 0.00),
                'user_id'             => $userId,
                'notes'               => $data['notes'] ?? null,
            ]);

            // 5. احتساب توزيع التكلفة على النواتج وامتصاص فروقات الكسور
            $calculatedItems = $this->calculateCostDistribution($itemsData, $sourceTotalCost);

            // 6. تسجيل حركة صرف الصنف الخام من المخزن
            $sourceUnitName = $sourceItemUnit->unit->name ?? 'حبة';
            $this->stockService->recordMovement(
                $disassembly->source_item_id,
                $disassembly->store_id,
                $sourceItemUnit->id,
                'adjustment',
                $disassembly->document_number,
                $sourceUnitName,
                -$sourceQuantity,
                $sourceUnitFactor,
                $sourceUnitCost,
                'صرف صنف خام للتفكيك بموجب إذن رقم: ' . $disassembly->document_number,
                $userId
            );

            // 7. إدراج سطور النواتج، تسجيل حركات الوارد المخزني، وتحديث تكلفة كروت الأصناف
            foreach ($calculatedItems as $row) {
                $disassembly->items()->create([
                    'item_id'             => $row['item_id'],
                    'item_unit_id'        => $row['item_unit_id'],
                    'quantity'            => $row['quantity'],
                    'expected_unit_price' => $row['expected_unit_price'],
                    'cost_percentage'     => $row['cost_percentage'],
                    'unit_cost'           => $row['unit_cost'],
                    'total_cost'          => $row['total_cost'],
                    'notes'               => $row['notes'] ?? null,
                ]);

                $outputItemUnit = ItemUnit::with(['unit', 'item'])
                    ->where('item_id', $row['item_id'])
                    ->where('id', $row['item_unit_id'])
                    ->firstOrFail();

                $outputFactor = (float) ($outputItemUnit->conversion_factor > 0 ? $outputItemUnit->conversion_factor : 1.00);
                $outputUnitName = $outputItemUnit->unit->name ?? 'حبة';

                // تسجيل حركة توريد للجزء الناتج في المخزن
                $this->stockService->recordMovement(
                    $row['item_id'],
                    $disassembly->store_id,
                    $outputItemUnit->id,
                    'adjustment',
                    $disassembly->document_number,
                    $outputUnitName,
                    $row['quantity'],
                    $outputFactor,
                    $row['unit_cost'],
                    'إدخال ناتج تفكيك بموجب إذن رقم: ' . $disassembly->document_number,
                    $userId
                );

                // تحديث سعر التكلفة للوحدة الأساسية للصنف الناتج
                $this->syncOutputItemCost($outputItemUnit, (float) $row['unit_cost'], $outputFactor);
            }

            return $disassembly->load(['store', 'sourceItem', 'sourceItemUnit.unit', 'items.item', 'items.itemUnit.unit', 'user']);
        });
    }

    /**
     * تعديل إذن تفكيك قائم وعكس أثره المخزني وإعادة بناء التكاليف بالكامل
     */
    public function updateDisassembly(ItemDisassembly $disassembly, array $data, int $userId): ItemDisassembly
    {
        return DB::transaction(function () use ($disassembly, $data, $userId) {
            // 1. مسح الحركات المخزنية السابقة تماماً لإعادة رصيد الخام وعكس توريد النواتج
            $this->stockService->clearDocumentMovements($disassembly->document_number);

            $itemsData = $data['items'];
            unset($data['items']);

            // 2. التحقق من كفاية الرصيد بعد عكس الحركة
            $sourceItemUnit = ItemUnit::with(['unit', 'item'])
                ->where('item_id', $data['source_item_id'])
                ->where('id', $data['source_item_unit_id'])
                ->firstOrFail();

            $sourceUnitFactor = (float) ($sourceItemUnit->conversion_factor > 0 ? $sourceItemUnit->conversion_factor : 1.00);
            $sourceQuantity = (float) $data['source_quantity'];
            $requiredBaseQuantity = $sourceQuantity * $sourceUnitFactor;

            $sourceStock = ItemStock::where('item_id', $data['source_item_id'])
                ->where('store_id', $data['store_id'])
                ->first();

            $availableStock = $sourceStock ? (float) $sourceStock->current_quantity : 0.00;
            if ($availableStock < $requiredBaseQuantity) {
                $availableInSelectedUnit = $sourceUnitFactor > 0 ? round($availableStock / $sourceUnitFactor, 2) : 0;
                throw new Exception("الرصيد المتاح من الصنف الخام بالمستودع غير كافٍ لإتمام التعديل. المطلوب: {$sourceQuantity}، المتاح: {$availableInSelectedUnit}");
            }

            // 3. تحديد تكلفة وحدة الصنف الخام
            if (isset($data['source_unit_cost']) && (float) $data['source_unit_cost'] > 0) {
                $sourceUnitCost = (float) $data['source_unit_cost'];
            } else {
                $sourceBaseUnit = ItemUnit::where('item_id', $sourceItemUnit->item_id)
                    ->where('unit_id', $sourceItemUnit->item->base_unit_id)
                    ->first();

                $baseCost = $sourceBaseUnit ? (float) $sourceBaseUnit->cost : (float) $sourceItemUnit->cost;
                $sourceUnitCost = $baseCost * $sourceUnitFactor;
            }

            $sourceTotalCost = round($sourceQuantity * $sourceUnitCost, 4);

            // 4. تحديث رأس المستند
            $disassembly->update([
                'disassembly_date'    => $data['disassembly_date'],
                'store_id'            => (int) $data['store_id'],
                'source_item_id'      => (int) $data['source_item_id'],
                'source_item_unit_id' => (int) $data['source_item_unit_id'],
                'source_quantity'     => $sourceQuantity,
                'source_unit_cost'    => $sourceUnitCost,
                'source_total_cost'   => $sourceTotalCost,
                'waste_quantity'      => (float) ($data['waste_quantity'] ?? 0.00),
                'notes'               => $data['notes'] ?? null,
            ]);

            // 5. حذف السطور القديمة واستبدالها
            $disassembly->items()->forceDelete();

            $calculatedItems = $this->calculateCostDistribution($itemsData, $sourceTotalCost);

            // 6. تسجيل حركة صرف الخام المحدثة
            $sourceUnitName = $sourceItemUnit->unit->name ?? 'حبة';
            $this->stockService->recordMovement(
                $disassembly->source_item_id,
                $disassembly->store_id,
                $sourceItemUnit->id,
                'adjustment',
                $disassembly->document_number,
                $sourceUnitName,
                -$sourceQuantity,
                $sourceUnitFactor,
                $sourceUnitCost,
                'تحديث صرف صنف خام للتفكيك بموجب إذن رقم: ' . $disassembly->document_number,
                $userId
            );

            // 7. إعادة إدراج النواتج وتسجيل الحركات وتحديث التكلفة
            foreach ($calculatedItems as $row) {
                $disassembly->items()->create([
                    'item_id'             => $row['item_id'],
                    'item_unit_id'        => $row['item_unit_id'],
                    'quantity'            => $row['quantity'],
                    'expected_unit_price' => $row['expected_unit_price'],
                    'cost_percentage'     => $row['cost_percentage'],
                    'unit_cost'           => $row['unit_cost'],
                    'total_cost'          => $row['total_cost'],
                    'notes'               => $row['notes'] ?? null,
                ]);

                $outputItemUnit = ItemUnit::with(['unit', 'item'])
                    ->where('item_id', $row['item_id'])
                    ->where('id', $row['item_unit_id'])
                    ->firstOrFail();

                $outputFactor = (float) ($outputItemUnit->conversion_factor > 0 ? $outputItemUnit->conversion_factor : 1.00);
                $outputUnitName = $outputItemUnit->unit->name ?? 'حبة';

                $this->stockService->recordMovement(
                    $row['item_id'],
                    $disassembly->store_id,
                    $outputItemUnit->id,
                    'adjustment',
                    $disassembly->document_number,
                    $outputUnitName,
                    $row['quantity'],
                    $outputFactor,
                    $row['unit_cost'],
                    'تحديث إدخال ناتج تفكيك بموجب إذن رقم: ' . $disassembly->document_number,
                    $userId
                );

                $this->syncOutputItemCost($outputItemUnit, (float) $row['unit_cost'], $outputFactor);
            }

            return $disassembly->load(['store', 'sourceItem', 'sourceItemUnit.unit', 'items.item', 'items.itemUnit.unit', 'user']);
        });
    }

    /**
     * إلغاء وحذف إذن التفكيك وعكس الحركات المخزنية بالكامل
     */
    public function deleteDisassembly(ItemDisassembly $disassembly): void
    {
        DB::transaction(function () use ($disassembly) {
            $this->stockService->clearDocumentMovements($disassembly->document_number);
            $disassembly->items()->delete();
            $disassembly->delete();
        });
    }

    /**
     * محرك توزيع التكلفة بالنسب البيعية أو الأوزان مع ضمان موازنة الكسور الحتمية 100%
     */
    protected function calculateCostDistribution(array $items, float $sourceTotalCost): array
    {
        $preparedItems = [];
        $totalSalesValue = 0.00;
        $totalQuantity = 0.00;

        foreach ($items as $item) {
            $qty = (float) $item['quantity'];
            $totalQuantity += $qty;

            $expectedPrice = isset($item['expected_unit_price']) && (float) $item['expected_unit_price'] > 0
                ? (float) $item['expected_unit_price']
                : 0.00;

            if ($expectedPrice <= 0) {
                $itemUnit = ItemUnit::find($item['item_unit_id']);
                $expectedPrice = (float) ($itemUnit?->price ?? 0.00);
            }

            $lineSalesValue = $qty * $expectedPrice;
            $totalSalesValue += $lineSalesValue;

            $preparedItems[] = [
                'item_id'             => (int) $item['item_id'],
                'item_unit_id'        => (int) $item['item_unit_id'],
                'quantity'            => $qty,
                'expected_unit_price' => $expectedPrice,
                'line_sales_value'    => $lineSalesValue,
                'notes'               => $item['notes'] ?? null,
            ];
        }

        $allocatedCostSum = 0.00;
        $result = [];
        $count = count($preparedItems);

        foreach ($preparedItems as $index => $row) {
            // تحديد النسبة المئوية للتكلفة: بالقيمة البيعية، وإن انعدمت فبنسبة الوزن
            if ($totalSalesValue > 0) {
                $percentage = ($row['line_sales_value'] / $totalSalesValue) * 100;
            } elseif ($totalQuantity > 0) {
                $percentage = ($row['quantity'] / $totalQuantity) * 100;
            } else {
                $percentage = 100 / $count;
            }

            // موازنة فروقات الكسور تلقائياً على السطر الأخير
            if ($index === $count - 1) {
                $lineTotalCost = round(max(0.00, $sourceTotalCost - $allocatedCostSum), 4);
                $unitCost = $row['quantity'] > 0 ? round($lineTotalCost / $row['quantity'], 4) : 0.00;
            } else {
                $lineTotalCost = round(($percentage / 100) * $sourceTotalCost, 4);
                $unitCost = $row['quantity'] > 0 ? round($lineTotalCost / $row['quantity'], 4) : 0.00;
                $allocatedCostSum += $lineTotalCost;
            }

            $result[] = [
                'item_id'             => $row['item_id'],
                'item_unit_id'        => $row['item_unit_id'],
                'quantity'            => $row['quantity'],
                'expected_unit_price' => $row['expected_unit_price'],
                'cost_percentage'     => round($percentage, 4),
                'unit_cost'           => $unitCost,
                'total_cost'          => $lineTotalCost,
                'notes'               => $row['notes'],
            ];
        }

        return $result;
    }

    /**
     * مزامنة تكلفة الصنف الناتج في كرت وحداته لضمان قراءتها الصحيحة عند البيع
     */
    protected function syncOutputItemCost(ItemUnit $itemUnit, float $unitCost, float $conversionFactor): void
    {
        $baseCost = $conversionFactor > 0 ? ($unitCost / $conversionFactor) : $unitCost;

        $siblingUnits = ItemUnit::where('item_id', $itemUnit->item_id)->get();
        foreach ($siblingUnits as $sibling) {
            $factor = (float) ($sibling->conversion_factor > 0 ? $sibling->conversion_factor : 1.00);
            $sibling->update([
                'cost' => round($baseCost * $factor, 4),
            ]);
        }
    }
}