<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\ItemBarcode;
use App\Models\ItemUnitPrice;
use App\Models\ItemComponent;
use Illuminate\Support\Facades\DB;

class ItemService
{
    /**
     * إنشاء صنف جديد مع كافة مصفوفات الوحدات والأسعار وسياسات التسعير والباركودات والمكونات التجميعية
     */
    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            // 1. تسجيل البيانات الأساسية الثابتة للصنف بما فيها سياسات التسعير وعلم التجميع وتاريخ الصلاحية
            $item = Item::create([
                'name'                  => $data['name'],
                'item_type'             => $data['item_type'],
                'profit_margin'         => $data['profit_margin'] ?? 0,
                'purchase_currency_id'  => $data['purchase_currency_id'] ?? null,
                'pricing_policy'        => $data['pricing_policy'] ?? 'manual',
                'min_margin_percentage' => $data['min_margin_percentage'] ?? 0,
                'rounding_rule'         => $data['rounding_rule'] ?? 'none',
                'category_id'           => $data['category_id'] ?? null,
                'category_path'         => $data['category_path'] ?? null,
                'base_unit_id'          => $data['base_unit_id'],
                'is_active'             => $data['is_active'] ?? true,
                'is_composite'          => $data['is_composite'] ?? false,
                'expiry_date'           => $data['expiry_date'] ?? null,
            ]);

            // 2. تدوين مصفوفة المكونات والمواد الخام إذا كان الصنف تجميعياً
            if ($item->is_composite && !empty($data['components'])) {
                foreach ($data['components'] as $componentData) {
                    ItemComponent::create([
                        'parent_item_id' => $item->id,
                        'child_item_id'  => $componentData['child_item_id'],
                        'quantity'       => $componentData['quantity'],
                    ]);
                }
            }

            // 3. تدوين مصفوفة الوحدات المرسلة عبر الـ Request
            foreach ($data['units'] as $unitData) {
                $itemUnit = ItemUnit::create([
                    'item_id'           => $item->id,
                    'unit_id'           => $unitData['unit_id'],
                    'conversion_factor' => $unitData['conversion_factor'],
                    'cost'              => $unitData['cost'],
                    'foreign_cost'      => $unitData['foreign_cost'] ?? null,
                    'price'             => $unitData['price'],
                ]);

                // 4. ربط وتدوين الباركودات المتعددة التابعة لهذه الوحدة
                if (!empty($unitData['barcodes'])) {
                    foreach ($unitData['barcodes'] as $barcode) {
                        ItemBarcode::create([
                            'item_id'      => $item->id,
                            'item_unit_id' => $itemUnit->id,
                            'barcode'      => $barcode,
                        ]);
                    }
                }

                // 5. بناء خلايا مصفوفة أسعار البيع وفئات الخصم لهذه الوحدة
                if (!empty($unitData['prices'])) {
                    foreach ($unitData['prices'] as $priceData) {
                        ItemUnitPrice::create([
                            'item_id'             => $item->id,
                            'item_unit_id'        => $itemUnit->id,
                            'price_list_id'       => $priceData['price_list_id'],
                            'discount_percentage' => $priceData['discount_percentage'] ?? 0,
                            'price'               => $priceData['price'],
                        ]);
                    }
                }
            }

            return $item->load(['units.unit', 'units.barcodes', 'units.prices.priceList', 'baseUnit', 'category', 'components.childItem', 'purchaseCurrency']);
        });
    }

    /**
     * تحديث بيانات الصنف ومزامنة مصفوفاته وسياسات التسعير بأمان كامل ومعالجة المكونات التجميعية بدقة
     */
    public function update(int $id, array $data): Item
    {
        return DB::transaction(function () use ($id, $data) {
            $item = Item::findOrFail($id);

            // 1. تحديث البيانات الإدارية وسياسات التسعير الثابتة للصنف
            $item->update([
                'name'                  => $data['name'],
                'item_type'             => $data['item_type'],
                'profit_margin'         => $data['profit_margin'] ?? 0,
                'purchase_currency_id'  => $data['purchase_currency_id'] ?? null,
                'pricing_policy'        => $data['pricing_policy'] ?? 'manual',
                'min_margin_percentage' => $data['min_margin_percentage'] ?? 0,
                'rounding_rule'         => $data['rounding_rule'] ?? 'none',
                'category_id'           => $data['category_id'] ?? null,
                'category_path'         => $data['category_path'] ?? null,
                'base_unit_id'          => $data['base_unit_id'],
                'is_active'             => $data['is_active'] ?? true,
                'is_composite'          => $data['is_composite'],
                'expiry_date'           => $data['expiry_date'] ?? null,
            ]);

            // 2. المزامنة الذكية للمكونات التجميعية: مسح القديم وإعادة البناء
            ItemComponent::where('parent_item_id', $item->id)->forceDelete();
            if ($item->is_composite && !empty($data['components'])) {
                foreach ($data['components'] as $componentData) {
                    ItemComponent::create([
                        'parent_item_id' => $item->id,
                        'child_item_id'  => $componentData['child_item_id'],
                        'quantity'       => $componentData['quantity'],
                    ]);
                }
            }

            // 3. استخراج مصفوفة معرفات الوحدات القادمة من الـ Request للمقارنة
            $incomingUnitIds = collect($data['units'])->pluck('unit_id')->toArray();

            // 4. التعامل مع الوحدات التي تم حذفها من الواجهة: تحويلها لحذف ناعم (Soft Delete)
            $unitsToRemove = ItemUnit::where('item_id', $item->id)
                ->whereNotIn('unit_id', $incomingUnitIds)
                ->get();

            foreach ($unitsToRemove as $oldUnit) {
                ItemBarcode::where('item_unit_id', $oldUnit->id)->forceDelete();
                ItemUnitPrice::where('item_unit_id', $oldUnit->id)->forceDelete();
                $oldUnit->delete();
            }

            // 5. المزامنة الذكية للوحدات المتبقية أو المعاد إحيائها
            foreach ($data['units'] as $unitData) {
                $itemUnit = ItemUnit::withTrashed()->updateOrCreate(
                    [
                        'item_id' => $item->id,
                        'unit_id' => $unitData['unit_id']
                    ],
                    [
                        'conversion_factor' => $unitData['conversion_factor'],
                        'cost'              => $unitData['cost'],
                        'foreign_cost'      => $unitData['foreign_cost'] ?? null,
                        'price'             => $unitData['price'],
                        'deleted_at'        => null
                    ]
                );

                // 6. تنظيف الأسعار والباركودات القديمة التابعة لهذه الوحدة لإعادة بنائها
                ItemBarcode::where('item_unit_id', $itemUnit->id)->forceDelete();
                ItemUnitPrice::where('item_unit_id', $itemUnit->id)->forceDelete();

                // 7. إعادة تسجيل الباركودات المحدثة للوحدة الحالية
                if (!empty($unitData['barcodes'])) {
                    foreach ($unitData['barcodes'] as $barcode) {
                        ItemBarcode::create([
                            'item_id'      => $item->id,
                            'item_unit_id' => $itemUnit->id,
                            'barcode'      => $barcode,
                        ]);
                    }
                }

                // 8. إعادة بناء فئات الأسعار والخصومات المحدثة للوحدة الحالية
                if (!empty($unitData['prices'])) {
                    foreach ($unitData['prices'] as $priceData) {
                        ItemUnitPrice::create([
                            'item_id'             => $item->id,
                            'item_unit_id'        => $itemUnit->id,
                            'price_list_id'       => $priceData['price_list_id'],
                            'discount_percentage' => $priceData['discount_percentage'] ?? 0,
                            'price'               => $priceData['price'],
                        ]);
                    }
                }
            }

            return $item->load(['units.unit', 'units.barcodes', 'units.prices.priceList', 'baseUnit', 'category', 'components.childItem', 'purchaseCurrency']);
        });
    }

    /**
     * حذف الصنف وحذف مصفوفاته بشكل أرشيفي ناعم تماشياً مع قواعد حماية التقارير والبيانات
     */
    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $item = Item::findOrFail($id);

            ItemBarcode::where('item_id', $item->id)->forceDelete();
            ItemUnitPrice::where('item_id', $item->id)->forceDelete();
            ItemComponent::where('parent_item_id', $item->id)->delete();
            ItemUnit::where('item_id', $item->id)->delete();

            return (bool) $item->delete();
        });
    }

    // =========================================================================
    // --- محركات القراءة والبحث والمخزون اللحظي (Stock & Search Engines) ---
    // =========================================================================

    /**
     * جلب قائمة الأصناف المفلترة مع دمج الكمية اللحظية وحد الطلب والمكونات التجميعية
     */
    public function searchWithStock(array $filters, ?int $storeId)
    {
        $query = Item::with([
            'units.unit',
            'units.barcodes',
            'units.prices.priceList',
            'baseUnit',
            'category',
            'purchaseCurrency',
            'components.childItem',
            'stocks' => function ($q) use ($storeId) {
                if ($storeId) {
                    $q->where('store_id', $storeId);
                }
            }
        ])
        ->when($storeId, function ($query) use ($storeId) {
            $query->withSum(['stocks as current_stock' => function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            }], 'current_quantity');
        })
        ->when(isset($filters['search']), function ($query) use ($filters) {
            $query->where(function ($subQuery) use ($filters) {
                $subQuery->where('name', 'like', '%' . $filters['search'] . '%')
                         ->orWhereHas('barcodes', function ($q) use ($filters) {
                             $q->where('barcode', $filters['search']);
                         });
            });
        })
        ->when(isset($filters['item_type']), function ($query) use ($filters) {
            $query->where('item_type', $filters['item_type']);
        })
        ->when(isset($filters['category_id']), function ($query) use ($filters) {
            $query->where('category_id', $filters['category_id']);
        })
        ->when(isset($filters['purchase_currency_id']), function ($query) use ($filters) {
            $query->where('purchase_currency_id', $filters['purchase_currency_id']);
        })
        ->when(isset($filters['pricing_policy']), function ($query) use ($filters) {
            $query->where('pricing_policy', $filters['pricing_policy']);
        })
        ->when(isset($filters['is_active']), function ($query) use ($filters) {
            $query->where('is_active', $filters['is_active']);
        })
        ->latest();

        return (isset($filters['all']) || request()->has('all')) ? $query->get() : $query->paginate(15);
    }

    /**
     * جلب خارطة كميات المخزون اللحظية مباشرة من جدول المخزن لسرعة أداء مطلقة وتفادي الـ N+1
     */
    public function refreshStockLevels(int $storeId, array $itemIds): array
    {
        return DB::table('item_stocks')
            ->where('store_id', $storeId)
            ->whereIn('item_id', $itemIds)
            ->pluck('current_quantity', 'item_id')
            ->toArray();
    }

    /**
     * تحديث أو إنشاء حد الطلب لصنف معين داخل مخزن محدد بأمان كامل وتوافقية مع الـ Casts
     */
    public function updateReorderLevel(int $itemId, int $storeId, float $reorderLevel): \App\Models\ItemStock
    {
        return DB::transaction(function () use ($itemId, $storeId, $reorderLevel) {
            Item::findOrFail($itemId);

            $stock = \App\Models\ItemStock::where('item_id', $itemId)
                ->where('store_id', $storeId)
                ->first();

            if ($stock) {
                $stock->update([
                    'reorder_level' => $reorderLevel,
                ]);
            } else {
                $stock = \App\Models\ItemStock::create([
                    'item_id'          => $itemId,
                    'store_id'         => $storeId,
                    'reorder_level'    => $reorderLevel,
                    'current_quantity' => 0.00,
                ]);
            }

            return $stock;
        });
    }
}