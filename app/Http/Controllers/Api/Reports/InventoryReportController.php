<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\ItemMovement;
use App\Models\StockAdjustment;
use Carbon\Carbon;

class InventoryReportController extends Controller
{
    /**
     * 1. تقرير الأرصدة اللحظية للمستودعات (Current Stock Evaluation)
     * عرض جرد الكميات الحالية المتوفرة مع تفكيك حركي وديناميكي كامل لمصفوفة الوحدات والأسعار وهوامش الربح
     */
    public function currentStock(Request $request): JsonResponse
    {
        $query = ItemStock::whereHas('item')
            ->with(['item.baseUnit', 'item.units.unit', 'store']);

        // فلترة مخصصة حسب المستودع
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // فلترة مخصصة حسب تصنيف الأصناف
        if ($request->filled('category_id')) {
            $query->whereHas('item', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // البحث السريع بالاسم أو الباركود
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('item', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('barcodes', function ($qb) use ($search) {
                      $qb->where('barcode', 'like', "%{$search}%");
                  });
            });
        }

        $stocks = $query->get();

        $reportData = $stocks->map(function ($stock) {
            $item = $stock->item;
            if (!$item) {
                return null;
            }

            $baseQty = (float) $stock->current_quantity;
            $units = $item->units ?? collect();

            // جلب تكلفة وسعر الوحدة الأساسية
            $baseUnitConfig = $units->firstWhere('unit_id', $item->base_unit_id);
            $baseCost = $baseUnitConfig ? (float) $baseUnitConfig->cost : 0.00;
            $basePrice = $baseUnitConfig ? (float) $baseUnitConfig->price : 0.00;

            // احتساب نسبة هامش الربح
            $profitMarginPercentage = $baseCost > 0
                ? round((($basePrice - $baseCost) / $baseCost) * 100, 2)
                : 0.00;

            // فرز الوحدات الفرعية الأخرى المتوفرة للصنف
            $nonBaseUnits = $units->where('unit_id', '!=', $item->base_unit_id)->values();
            $unit2Config = $nonBaseUnits->get(0);
            $unit3Config = $nonBaseUnits->get(1);

            $u2Factor = $unit2Config ? (float) ($unit2Config->conversion_factor > 0 ? $unit2Config->conversion_factor : 1.00) : 1.00;
            $u3Factor = $unit3Config ? (float) ($unit3Config->conversion_factor > 0 ? $unit3Config->conversion_factor : 1.00) : 1.00;

            return [
                'stock_id'                => $stock->id,
                'store_name'              => $stock->store->name ?? null,
                'item_id'                 => $item->id,
                'item_name'               => $item->name,
                'current_quantity_base'   => $baseQty,
                'unit1_name'              => $item->baseUnit->name ?? null,

                'has_unit2'               => !is_null($unit2Config),
                'qty_in_unit2'            => $unit2Config ? round($baseQty / $u2Factor, 4) : 0.00,
                'unit2_name'              => $unit2Config->unit->name ?? null,

                'has_unit3'               => !is_null($unit3Config),
                'qty_in_unit3'            => $unit3Config ? round($baseQty / $u3Factor, 4) : 0.00,
                'unit3_name'              => $unit3Config->unit->name ?? null,

                'units_breakdown'         => $units->map(function ($u) use ($baseQty) {
                    $f = (float) ($u->conversion_factor > 0 ? $u->conversion_factor : 1.00);
                    return [
                        'unit_id'    => $u->unit_id,
                        'unit_name'  => $u->unit->name ?? null,
                        'quantity'   => round($baseQty / $f, 4),
                        'cost'       => (float) $u->cost,
                        'price'      => (float) $u->price
                    ];
                }),

                'unit_cost'               => $baseCost,
                'unit_price'              => $basePrice,
                'profit_margin_percentage'=> $profitMarginPercentage,
                'total_cost_value'        => round($baseQty * $baseCost, 2),
                'total_sale_value'        => round($baseQty * $basePrice, 2)
            ];
        })->filter()->values();

        // احتساب الإجماليات العامة لكروت التقرير
        $totalItems = $reportData->count();
        $grandTotalQuantity = (float) $reportData->sum('current_quantity_base');
        $grandTotalCostValue = (float) $reportData->sum('total_cost_value');
        $grandTotalSaleValue = (float) $reportData->sum('total_sale_value');

        return response()->json([
            'success' => true,
            'summary' => [
                'total_items'             => $totalItems,
                'grand_total_quantity'   => $grandTotalQuantity,
                'grand_total_cost_value' => round($grandTotalCostValue, 2),
                'grand_total_sale_value' => round($grandTotalSaleValue, 2),
            ],
            'data'    => $reportData
        ]);
    }

    /**
     * 2. كارت حركة الصنف التفصيلي (Stock Card / Item Ledger)
     */
    public function stockCard(Request $request): JsonResponse
    {
        $request->validate([
            'item_id' => ['required', 'exists:items,id'],
        ]);

        $itemId = $request->item_id;
        $storeId = $request->store_id;
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : null;
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        $openingQuery = ItemMovement::where('item_id', $itemId);
        if ($storeId) {
            $openingQuery->where('store_id', $storeId);
        }

        if ($fromDate) {
            $openingQuery->where('created_at', '<', $fromDate);
            $openingBalance = (float) $openingQuery->sum('base_quantity');
        } else {
            $openingBalance = 0.00;
        }

        $movementsQuery = ItemMovement::with(['store', 'itemUnit.unit'])->where('item_id', $itemId);
        if ($storeId) {
            $movementsQuery->where('store_id', $storeId);
        }
        if ($fromDate) {
            $movementsQuery->where('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $movementsQuery->where('created_at', '<=', $toDate);
        }

        $movements = $movementsQuery->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $runningBalance = $openingBalance;
        $reportLines = [];

        foreach ($movements as $move) {
            $baseQty = (float) $move->base_quantity;
            $runningBalance += $baseQty;

            $reportLines[] = [
                'id'              => $move->id,
                'date'            => $move->created_at->format('Y-m-d H:i:s'),
                'movement_type'   => $move->movement_type,
                'document_no'     => $move->document_no,
                'store_name'      => $move->store->name ?? null,
                'unit_name_used'  => $move->unit_name_used,
                'quantity'        => (float) $move->quantity,
                'base_quantity'   => $baseQty,
                'cost_price'      => (float) $move->cost_price,
                'direction'       => $baseQty >= 0 ? 'in' : 'out',
                'direction_lbl'   => $baseQty >= 0 ? 'وارد / حيازة' : 'صادر / منصرف',
                'running_balance' => $runningBalance,
                'notes'           => $move->notes
            ];
        }

        return response()->json([
            'success' => true,
            'meta'    => [
                'item_id'         => (int) $itemId,
                'item_name'       => Item::find($itemId)->name ?? '',
                'opening_balance' => $openingBalance,
                'closing_balance' => $runningBalance,
            ],
            'data'    => $reportLines
        ]);
    }

    /**
     * 3. تقرير تقييم المخزون المالي (Inventory Valuation Report)
     */
    public function stockValuation(Request $request): JsonResponse
    {
        $query = ItemStock::whereHas('item')
            ->with(['item.baseUnit', 'item.units', 'store']);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $stocks = $query->get();

        $totalValuation = 0.00;
        $itemsData = $stocks->map(function ($stock) use (&$totalValuation) {
            $item = $stock->item;
            if (!$item) {
                return null;
            }

            $qty = (float) $stock->current_quantity;
            $units = $item->units ?? collect();

            $baseUnitConfig = $units->firstWhere('unit_id', $item->base_unit_id);
            $cost = $baseUnitConfig ? (float) $baseUnitConfig->cost : 0.00;

            $lineValue = $qty * $cost;
            $totalValuation += $lineValue;

            return [
                'store_name'       => $stock->store->name ?? null,
                'item_id'          => $item->id,
                'item_name'        => $item->name,
                'current_quantity' => $qty,
                'unit_name'        => $item->baseUnit->name ?? null,
                'unit_cost'        => $cost,
                'total_value'      => round($lineValue, 2)
            ];
        })->filter()->values();

        return response()->json([
            'success'               => true,
            'total_inventory_value' => round($totalValuation, 2),
            'data'                  => $itemsData
        ]);
    }

    /**
     * 4. تقرير ملخص التسويات وفروقات الجرد (Stock Adjustments Summary)
     */
    public function adjustmentsSummary(Request $request): JsonResponse
    {
        $query = StockAdjustment::with(['store', 'user', 'items.item']);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('from_date')) {
            $query->where('adjustment_date', '>=', Carbon::parse($request->from_date)->startOfDay());
        }

        if ($request->filled('to_date')) {
            $query->where('adjustment_date', '<=', Carbon::parse($request->to_date)->endOfDay());
        }

        $adjustments = $query->latest('adjustment_date')->get();

        $grandTotalShortage = 0.00;
        $grandTotalSurplus = 0.00;

        $reportData = $adjustments->map(function ($adj) use (&$grandTotalShortage, &$grandTotalSurplus) {
            $shortageValue = 0.00;
            $surplusValue = 0.00;

            foreach ($adj->items as $item) {
                $diff = (float) $item->quantity_difference;
                $cost = (float) $item->unit_cost;
                $value = abs($diff * $cost);

                if ($diff < 0) {
                    $shortageValue += $value;
                } else {
                    $surplusValue += $value;
                }
            }

            $grandTotalShortage += $shortageValue;
            $grandTotalSurplus += $surplusValue;

            return [
                'id'                 => $adj->id,
                'adjustment_number'  => $adj->adjustment_number,
                'date'               => $adj->adjustment_date->format('Y-m-d H:i:s'),
                'store_name'         => $adj->store->name ?? null,
                'created_by'         => $adj->user->full_name ?? $adj->user->name ?? null,
                'shortage_value'     => round($shortageValue, 2),
                'surplus_value'      => round($surplusValue, 2),
                'items_count'        => $adj->items->count(),
                'notes'              => $adj->notes
            ];
        });

        return response()->json([
            'success'              => true,
            'grand_total_shortage' => round($grandTotalShortage, 2),
            'grand_total_surplus'  => round($grandTotalSurplus, 2),
            'data'                 => $reportData
        ]);
    }
}