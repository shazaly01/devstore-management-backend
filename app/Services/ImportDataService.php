<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Account;
use App\Models\Unit;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\ItemBarcode;
use App\Services\SubLedgerOpeningBalanceService;
use App\Services\ItemService;
use Illuminate\Support\Facades\DB;

class ImportDataService
{
    protected SubLedgerOpeningBalanceService $openingBalanceService;
    protected ItemService $itemService;

    public function __construct(
        SubLedgerOpeningBalanceService $openingBalanceService,
        ItemService $itemService
    ) {
        $this->openingBalanceService = $openingBalanceService;
        $this->itemService = $itemService;
    }

    /**
     * استيراد مصفوفة العملاء ومزامنة أرصدتهم الافتتاحية محاسبياً
     */
    public function importCustomers(array $customers): array
    {
        return DB::transaction(function () use ($customers) {
            $parentAccount = Account::where('code', Account::CODE_CUSTOMERS)->firstOrFail();
            $importedCount = 0;

            foreach ($customers as $data) {
                $openingBalance = isset($data['opening_balance']) ? (float)$data['opening_balance'] : 0.00;

                $customer = Customer::create([
                    'name'            => trim($data['name']),
                    'phone'           => !empty($data['phone']) ? trim($data['phone']) : null,
                    'email'           => !empty($data['email']) ? trim($data['email']) : null,
                    'credit_limit'    => isset($data['credit_limit']) ? (float)$data['credit_limit'] : 0.00,
                    'account_id'      => $parentAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'price_list_id'   => !empty($data['price_list_id']) ? (int)$data['price_list_id'] : null,
                ]);

                if ($openingBalance > 0) {
                    $this->openingBalanceService->syncOpeningBalance($customer, $openingBalance);
                }

                $importedCount++;
            }

            return [
                'imported_count' => $importedCount,
            ];
        });
    }

    /**
     * استيراد مصفوفة الموردين ومزامنة أرصدتهم الافتتاحية محاسبياً
     */
    public function importSuppliers(array $suppliers): array
    {
        return DB::transaction(function () use ($suppliers) {
            $parentAccount = Account::where('code', Account::CODE_SUPPLIERS)->firstOrFail();
            $importedCount = 0;

            foreach ($suppliers as $data) {
                $openingBalance = isset($data['opening_balance']) ? (float)$data['opening_balance'] : 0.00;

                $supplier = Supplier::create([
                    'name'            => trim($data['name']),
                    'phone'           => !empty($data['phone']) ? trim($data['phone']) : null,
                    'tax_number'      => !empty($data['tax_number']) ? trim($data['tax_number']) : null,
                    'account_id'      => $parentAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                ]);

                if ($openingBalance > 0) {
                    $this->openingBalanceService->syncOpeningBalance($supplier, $openingBalance);
                }

                $importedCount++;
            }

            return [
                'imported_count' => $importedCount,
            ];
        });
    }

    /**
     * استيراد وتحديث مصفوفة الأصناف ومنع التكرار (Upsert: تحديث الأسعار إن وجد الصنف مسبقاً)
     */
    public function importItems(array $items): array
    {
        return DB::transaction(function () use ($items) {
            $createdCount = 0;
            $updatedCount = 0;

            // جلب الوحدة رقم 1 من قاعدة البيانات ديناميكياً لتكون الوحدة الافتراضية
            $defaultUnit = Unit::find(1) ?? Unit::first();
            if (!$defaultUnit) {
                $defaultUnit = Unit::create([
                    'name'       => 'قطعة',
                    'short_name' => 'قطعة',
                    'is_active'  => true,
                ]);
            }

            foreach ($items as $data) {
                $name = trim($data['name']);
                $barcode = !empty($data['barcode']) ? trim((string)$data['barcode']) : null;

                // 1. تحديد الوحدة المستهدفة لهذا السطر
                if (!empty($data['unit_name']) && trim($data['unit_name']) !== '') {
                    $unitName = trim($data['unit_name']);
                    $unit = Unit::firstOrCreate(
                        ['name' => $unitName],
                        [
                            'short_name' => $unitName,
                            'is_active'  => true,
                        ]
                    );
                } else {
                    $unit = $defaultUnit;
                }

                // 2. التحقق من وجود الصنف مسبقاً (بالباركود أولاً ثم بالاسم)
                $existingItem = null;

                if ($barcode) {
                    $barcodeRecord = ItemBarcode::where('barcode', $barcode)->first();
                    if ($barcodeRecord) {
                        $existingItem = Item::find($barcodeRecord->item_id);
                    }
                }

                if (!$existingItem) {
                    $existingItem = Item::where('name', $name)->first();
                }

                $cost = isset($data['cost']) && is_numeric($data['cost'])
                    ? (float)$data['cost']
                    : null;

                $price = isset($data['price']) && is_numeric($data['price'])
                    ? (float)$data['price']
                    : null;

                // 3. المسار الأول: الصنف موجود مسبقاً -> تحديث الأسعار دون تكرار السجل
                if ($existingItem) {
                    // البحث عن سجل الوحدة المستهدفة لهذا الصنف
                    $itemUnit = ItemUnit::where('item_id', $existingItem->id)
                        ->where('unit_id', $unit->id)
                        ->first();

                    // إذا لم توجد الوحدة المطابقة، نستهدف الوحدة الأساسية للصنف
                    if (!$itemUnit) {
                        $itemUnit = ItemUnit::where('item_id', $existingItem->id)
                            ->where('unit_id', $existingItem->base_unit_id)
                            ->first() ?? ItemUnit::where('item_id', $existingItem->id)->first();
                    }

                    if ($itemUnit) {
                        $updates = [];
                        // تحديث التكلفة فقط إذا كانت قيمة رقمية موجبة ممررة في الإكسيل
                        if ($cost !== null && $cost > 0) {
                            $updates['cost'] = $cost;
                        }
                        // تحديث سعر البيع فقط إذا كانت قيمة رقمية موجبة ممررة في الإكسيل
                        if ($price !== null && $price > 0) {
                            $updates['price'] = $price;
                        }

                        if (!empty($updates)) {
                            $itemUnit->update($updates);
                        }

                        // ربط الباركود بالوحدة إذا توفر ولم يكن مسجلاً لأي صنف آخر
                        if ($barcode && !ItemBarcode::where('barcode', $barcode)->exists()) {
                            ItemBarcode::create([
                                'item_id'      => $existingItem->id,
                                'item_unit_id' => $itemUnit->id,
                                'barcode'      => $barcode,
                            ]);
                        }
                    }

                    $updatedCount++;
                } else {
                    // 4. المسار الثاني: صنف جديد تماماً -> إنشاء السجل والمصفوفات
                    $barcodes = $barcode ? [$barcode] : [];

                    $itemPayload = [
                        'name'                  => $name,
                        'item_type'             => 'product',
                        'profit_margin'         => 0,
                        'pricing_policy'        => 'manual',
                        'base_unit_id'          => $unit->id,
                        'is_active'             => true,
                        'is_composite'          => false,
                        'category_id'           => !empty($data['category_id']) ? (int)$data['category_id'] : null,
                        'units'                 => [
                            [
                                'unit_id'           => $unit->id,
                                'conversion_factor' => 1.0,
                                'cost'              => $cost ?? 0.00,
                                'foreign_cost'      => null,
                                'price'             => $price ?? 0.00,
                                'barcodes'          => $barcodes,
                                'prices'            => [],
                            ]
                        ],
                    ];

                    $this->itemService->create($itemPayload);
                    $createdCount++;
                }
            }

            return [
                'created_count'  => $createdCount,
                'updated_count'  => $updatedCount,
                'imported_count' => $createdCount + $updatedCount,
            ];
        });
    }
}