<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use App\Models\Account;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Treasury;
use App\Models\Bank;
use App\Models\Store;
use App\Models\Unit;
use App\Models\PriceList;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\Customer;
use App\Models\User;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\ItemBarcode;
use App\Models\ItemUnitPrice;
use App\Models\ItemComponent;
use App\Models\ItemStock;
use App\Models\OpeningStock;
use App\Models\OpeningStockItem;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;

class DemoDataSeeder extends Seeder
{
    /**
     * تشغيل بذر بيانات تجريبية شاملة تغطي الخامات، الأوزان (طن/كجم)، السلع، والخدمات
     */
    public function run(): void
    {
        DB::transaction(function () {
            // =========================================================================
            // 1. استرجاع الحسابات السيادية من شجرة الحسابات
            // =========================================================================
            $treasuryAccount    = Account::where('code', Account::CODE_TREASURY)->firstOrFail();
            $bankAccount        = Account::where('code', Account::CODE_BANKS)->firstOrFail();
            $inventoryAccount   = Account::where('code', Account::CODE_INVENTORY)->firstOrFail();
            $customerAccount    = Account::where('code', Account::CODE_CUSTOMERS)->firstOrFail();
            $supplierAccount    = Account::where('code', Account::CODE_SUPPLIERS)->firstOrFail();
            $paidCapitalAccount = Account::where('code', Account::CODE_PAID_CAPITAL)->firstOrFail();
            $assetCapitalAccount= Account::where('code', Account::CODE_ASSET_CAPITAL)->firstOrFail();
            $expenseAccount     = Account::where('code', Account::CODE_EXPENSES)->firstOrFail();

            // =========================================================================
            // 2. العملات وأسعار الصرف
            // =========================================================================
            $baseCurrency = Currency::where('is_default', true)->first()
                ?? Currency::where('code', 'SDG')->firstOrFail();

            $usdCurrency = Currency::where('code', 'USD')->first();

            if ($usdCurrency) {
                ExchangeRate::updateOrCreate(
                    [
                        'currency_id' => $usdCurrency->id,
                        'rate_date'   => now()->toDateString(),
                    ],
                    [
                        'rate'    => 2500.00,
                        'user_id' => null,
                        'notes'   => 'سعر الصرف الافتتاحي المرجعي للدولار',
                    ]
                );
            }

            // =========================================================================
            // 3. وحدات القياس المتنوعة (أوزان، تعبئة، قطع، وخدمات)
            // =========================================================================
            $unitKg     = Unit::updateOrCreate(['name' => 'كيلوجرام'], ['short_name' => 'كجم', 'is_active' => true]);
            $unitTon    = Unit::updateOrCreate(['name' => 'طن متري'], ['short_name' => 'طن', 'is_active' => true]);
            $unitBag    = Unit::updateOrCreate(['name' => 'شوال / كيس 50 كجم'], ['short_name' => 'شوال', 'is_active' => true]);
            $unitPiece  = Unit::updateOrCreate(['name' => 'قطعة'], ['short_name' => 'قطعة', 'is_active' => true]);
            $unitBox    = Unit::updateOrCreate(['name' => 'صندوق'], ['short_name' => 'صندوق', 'is_active' => true]);
            $unitService= Unit::updateOrCreate(['name' => 'خدمة'], ['short_name' => 'خدمة', 'is_active' => true]);

            // =========================================================================
            // 4. قوائم فئات الأسعار
            // =========================================================================
            $retailPriceList = PriceList::updateOrCreate(
                ['name' => 'سعر التجزئة (قطاعي)'],
                ['is_default' => true]
            );

            $wholesalePriceList = PriceList::updateOrCreate(
                ['name' => 'سعر الجملة / الشركات'],
                ['is_default' => false]
            );

            // =========================================================================
            // 5. المستودعات والخزائن والبنوك
            // =========================================================================
            $mainStore = Store::updateOrCreate(
                ['name' => 'المستودع الرئيسي والخامات'],
                [
                    'location'   => 'المنطقة اللوجستية',
                    'account_id' => $inventoryAccount->id,
                    'is_active'  => true,
                ]
            );

            $posStore = Store::updateOrCreate(
                ['name' => 'صالة البيع والتسليم'],
                [
                    'location'   => 'الفرع التجاري',
                    'account_id' => $inventoryAccount->id,
                    'is_active'  => true,
                ]
            );

            $mainTreasury = Treasury::updateOrCreate(
                ['name' => 'الخزينة الرئيسية'],
                [
                    'account_id'      => $treasuryAccount->id,
                    'opening_balance' => 500000.00,
                    'current_balance' => 500000.00,
                    'is_active'       => true,
                ]
            );

            $posTreasury = Treasury::updateOrCreate(
                ['name' => 'صندوق الكاشير'],
                [
                    'account_id'      => $treasuryAccount->id,
                    'opening_balance' => 100000.00,
                    'current_balance' => 100000.00,
                    'is_active'       => true,
                ]
            );

            $mainBank = Bank::updateOrCreate(
                ['account_number' => '1020304050'],
                [
                    'name'            => 'الحساب البنكي التجاري',
                    'iban'            => 'SD00BOK000001020304050',
                    'account_id'      => $bankAccount->id,
                    'opening_balance' => 1400000.00,
                    'current_balance' => 1400000.00,
                    'is_active'       => true,
                ]
            );

            // =========================================================================
            // 6. بنود المصروفات التشغيلية
            // =========================================================================
            Expense::updateOrCreate(
                ['name' => 'إيجار المستودعات والمقار'],
                [
                    'account_id'      => $expenseAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'is_active'       => true,
                ]
            );

            Expense::updateOrCreate(
                ['name' => 'فواتير الطاقة والمحروقات'],
                [
                    'account_id'      => $expenseAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'is_active'       => true,
                ]
            );

            // =========================================================================
            // 7. المستخدمين والأدوار
            // =========================================================================
            $adminRole   = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
            $cashierRole = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'api']);

            $adminUser = User::updateOrCreate(
                ['username' => 'admin_demo'],
                [
                    'full_name'     => 'مدير النظام',
                    'email'         => 'admin@demo.com',
                    'password'      => Hash::make('password123'),
                    'type'          => 'regular',
                    'store_id'      => $mainStore->id,
                    'treasury_id'   => $mainTreasury->id,
                    'bank_id'       => $mainBank->id,
                    'stock_control' => true,
                ]
            );
            $adminUser->syncRoles([$adminRole]);

            $cashierUser = User::updateOrCreate(
                ['username' => 'cashier_demo'],
                [
                    'full_name'     => 'موظف المبيعات',
                    'email'         => 'cashier@demo.com',
                    'password'      => Hash::make('password123'),
                    'type'          => 'regular',
                    'store_id'      => $posStore->id,
                    'treasury_id'   => $posTreasury->id,
                    'bank_id'       => $mainBank->id,
                    'stock_control' => true,
                ]
            );
            $cashierUser->syncRoles([$cashierRole]);

            // =========================================================================
            // 8. شركاء الأعمال
            // =========================================================================
            Supplier::updateOrCreate(
                ['name' => 'شركة التوريدات والاستيراد'],
                [
                    'phone'           => '0912345678',
                    'tax_number'      => '100200300',
                    'account_id'      => $supplierAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                ]
            );

            Customer::updateOrCreate(
                ['name' => 'عميل نقدي عام'],
                [
                    'phone'           => '0000000000',
                    'credit_limit'    => 0.00,
                    'account_id'      => $customerAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'price_list_id'   => $retailPriceList->id,
                ]
            );

            Customer::updateOrCreate(
                ['name' => 'مؤسسة الأعمال المتقدمة (آجل)'],
                [
                    'phone'           => '0123456789',
                    'credit_limit'    => 500000.00,
                    'account_id'      => $customerAccount->id,
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'price_list_id'   => $wholesalePriceList->id,
                ]
            );

            // =========================================================================
            // 9. شجرة التصنيفات العامة والشاملة
            // =========================================================================
            $catRawMaterials = Category::updateOrCreate(['name' => 'مواد خام وتعدين'], ['parent_id' => null, 'is_active' => true]);
            $catBuilding     = Category::updateOrCreate(['name' => 'مواد بناء ومقاولات'], ['parent_id' => null, 'is_active' => true]);
            $catEquipment    = Category::updateOrCreate(['name' => 'معدات وأجهزة'], ['parent_id' => null, 'is_active' => true]);
            $catSpareParts   = Category::updateOrCreate(['name' => 'قطع غيار ولوازم'], ['parent_id' => null, 'is_active' => true]);
            $catServices     = Category::updateOrCreate(['name' => 'خدمات لوجستية وفنية'], ['parent_id' => null, 'is_active' => true]);
            $catBundles      = Category::updateOrCreate(['name' => 'باقات وعروض'], ['parent_id' => null, 'is_active' => true]);

            // =========================================================================
            // 10. دليل الأصناف الشامل (خامات، أوزان، قطع، خدمات، وباقات)
            // =========================================================================
            $createdItems = [];

            // 10.1 مادة خام تباع بالكيلو والطن: حديد تسليح
            $itemSteel = Item::updateOrCreate(
                ['name' => 'حديد تسليح قياسي'],
                [
                    'category_id'           => $catRawMaterials->id,
                    'category_path'         => $catRawMaterials->path,
                    'item_type'             => 'raw_material',
                    'profit_margin'         => 15.00,
                    'purchase_currency_id'  => $baseCurrency->id,
                    'pricing_policy'        => 'manual',
                    'min_margin_percentage' => 5.00,
                    'rounding_rule'         => 'none',
                    'base_unit_id'          => $unitKg->id,
                    'is_active'             => true,
                    'is_composite'          => false,
                ]
            );
            $unitSteelKg = ItemUnit::updateOrCreate(
                ['item_id' => $itemSteel->id, 'unit_id' => $unitKg->id],
                ['conversion_factor' => 1.0, 'cost' => 800.00, 'foreign_cost' => 0.0, 'price' => 950.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemSteel->id, 'item_unit_id' => $unitSteelKg->id], ['barcode' => 'RAW-STL-KG']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemSteel->id, 'item_unit_id' => $unitSteelKg->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 950.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemSteel->id, 'item_unit_id' => $unitSteelKg->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 5.26, 'price' => 900.00]);

            $unitSteelTon = ItemUnit::updateOrCreate(
                ['item_id' => $itemSteel->id, 'unit_id' => $unitTon->id],
                ['conversion_factor' => 1000.0, 'cost' => 800000.00, 'foreign_cost' => 0.0, 'price' => 920000.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemSteel->id, 'item_unit_id' => $unitSteelTon->id], ['barcode' => 'RAW-STL-TON']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemSteel->id, 'item_unit_id' => $unitSteelTon->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 920000.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemSteel->id, 'item_unit_id' => $unitSteelTon->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 4.35, 'price' => 880000.00]);

            $createdItems[] = ['item' => $itemSteel, 'base_unit' => $unitSteelKg, 'qty' => 5000.0, 'cost' => 800.00]; // 5 طن = 4,000,000

            // 10.2 سلع بناء ومواد تعبئة تباع بالشوال والطن: أسمنت تشييد
            $itemCement = Item::updateOrCreate(
                ['name' => 'أسمنت تشييد فاخر'],
                [
                    'category_id'           => $catBuilding->id,
                    'category_path'         => $catBuilding->path,
                    'item_type'             => 'product',
                    'profit_margin'         => 16.67,
                    'purchase_currency_id'  => $baseCurrency->id,
                    'pricing_policy'        => 'manual',
                    'min_margin_percentage' => 5.00,
                    'rounding_rule'         => 'none',
                    'base_unit_id'          => $unitBag->id,
                    'is_active'             => true,
                    'is_composite'          => false,
                ]
            );
            $unitCementBag = ItemUnit::updateOrCreate(
                ['item_id' => $itemCement->id, 'unit_id' => $unitBag->id],
                ['conversion_factor' => 1.0, 'cost' => 3000.00, 'foreign_cost' => 0.0, 'price' => 3500.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemCement->id, 'item_unit_id' => $unitCementBag->id], ['barcode' => 'BLD-CMT-BAG']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemCement->id, 'item_unit_id' => $unitCementBag->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 3500.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemCement->id, 'item_unit_id' => $unitCementBag->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 5.71, 'price' => 3300.00]);

            $unitCementTon = ItemUnit::updateOrCreate(
                ['item_id' => $itemCement->id, 'unit_id' => $unitTon->id],
                ['conversion_factor' => 20.0, 'cost' => 60000.00, 'foreign_cost' => 0.0, 'price' => 68000.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemCement->id, 'item_unit_id' => $unitCementTon->id], ['barcode' => 'BLD-CMT-TON']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemCement->id, 'item_unit_id' => $unitCementTon->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 68000.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemCement->id, 'item_unit_id' => $unitCementTon->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 4.41, 'price' => 65000.00]);

            $createdItems[] = ['item' => $itemCement, 'base_unit' => $unitCementBag, 'qty' => 200.0, 'cost' => 3000.00]; // 10 طن = 600,000

            // 10.3 معدات وتجهيزات بالقطعة: جهاز قياس وتحكم
            $itemDevice = Item::updateOrCreate(
                ['name' => 'جهاز قياس وفحص رقمي'],
                [
                    'category_id'           => $catEquipment->id,
                    'category_path'         => $catEquipment->path,
                    'item_type'             => 'product',
                    'profit_margin'         => 25.00,
                    'purchase_currency_id'  => $baseCurrency->id,
                    'pricing_policy'        => 'manual',
                    'min_margin_percentage' => 10.00,
                    'rounding_rule'         => 'none',
                    'base_unit_id'          => $unitPiece->id,
                    'is_active'             => true,
                    'is_composite'          => false,
                ]
            );
            $unitDevicePiece = ItemUnit::updateOrCreate(
                ['item_id' => $itemDevice->id, 'unit_id' => $unitPiece->id],
                ['conversion_factor' => 1.0, 'cost' => 120000.00, 'foreign_cost' => 0.0, 'price' => 150000.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemDevice->id, 'item_unit_id' => $unitDevicePiece->id], ['barcode' => 'EQP-DEV-001']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemDevice->id, 'item_unit_id' => $unitDevicePiece->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 150000.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemDevice->id, 'item_unit_id' => $unitDevicePiece->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 8.00, 'price' => 138000.00]);

            $createdItems[] = ['item' => $itemDevice, 'base_unit' => $unitDevicePiece, 'qty' => 10.0, 'cost' => 120000.00]; // 1,200,000

            // 10.4 قطع غيار ومستلزمات تباع بالقطعة والصندوق: فلتر تشغيل قياسي
            $itemFilter = Item::updateOrCreate(
                ['name' => 'فلتر تشغيل قياسي'],
                [
                    'category_id'           => $catSpareParts->id,
                    'category_path'         => $catSpareParts->path,
                    'item_type'             => 'product',
                    'profit_margin'         => 30.00,
                    'purchase_currency_id'  => $baseCurrency->id,
                    'pricing_policy'        => 'manual',
                    'min_margin_percentage' => 10.00,
                    'rounding_rule'         => 'none',
                    'base_unit_id'          => $unitPiece->id,
                    'is_active'             => true,
                    'is_composite'          => false,
                ]
            );
            $unitFilterPiece = ItemUnit::updateOrCreate(
                ['item_id' => $itemFilter->id, 'unit_id' => $unitPiece->id],
                ['conversion_factor' => 1.0, 'cost' => 4000.00, 'foreign_cost' => 0.0, 'price' => 5500.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemFilter->id, 'item_unit_id' => $unitFilterPiece->id], ['barcode' => 'SPR-FLT-PC']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemFilter->id, 'item_unit_id' => $unitFilterPiece->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 5500.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemFilter->id, 'item_unit_id' => $unitFilterPiece->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 9.09, 'price' => 5000.00]);

            $unitFilterBox = ItemUnit::updateOrCreate(
                ['item_id' => $itemFilter->id, 'unit_id' => $unitBox->id],
                ['conversion_factor' => 10.0, 'cost' => 40000.00, 'foreign_cost' => 0.0, 'price' => 52000.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemFilter->id, 'item_unit_id' => $unitFilterBox->id], ['barcode' => 'SPR-FLT-BX']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemFilter->id, 'item_unit_id' => $unitFilterBox->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 52000.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemFilter->id, 'item_unit_id' => $unitFilterBox->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 7.69, 'price' => 48000.00]);

            $createdItems[] = ['item' => $itemFilter, 'base_unit' => $unitFilterPiece, 'qty' => 50.0, 'cost' => 4000.00]; // 200,000

            // 10.5 صنف خدمي: خدمة شحن ونقل لوجستي
            $itemService = Item::updateOrCreate(
                ['name' => 'خدمة نقل وشحن لوجستي'],
                [
                    'category_id'           => $catServices->id,
                    'category_path'         => $catServices->path,
                    'item_type'             => 'service',
                    'profit_margin'         => 100.00,
                    'purchase_currency_id'  => $baseCurrency->id,
                    'pricing_policy'        => 'manual',
                    'min_margin_percentage' => 0.00,
                    'rounding_rule'         => 'none',
                    'base_unit_id'          => $unitService->id,
                    'is_active'             => true,
                    'is_composite'          => false,
                ]
            );
            $unitServiceUnit = ItemUnit::updateOrCreate(
                ['item_id' => $itemService->id, 'unit_id' => $unitService->id],
                ['conversion_factor' => 1.0, 'cost' => 0.00, 'foreign_cost' => 0.0, 'price' => 25000.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemService->id, 'item_unit_id' => $unitServiceUnit->id], ['barcode' => 'SRV-SHP-01']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemService->id, 'item_unit_id' => $unitServiceUnit->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 25000.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemService->id, 'item_unit_id' => $unitServiceUnit->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 12.00, 'price' => 22000.00]);

            // 10.6 صنف تجميعي: طقم تجهيز وتشغيل متكامل
            $itemBundle = Item::updateOrCreate(
                ['name' => 'طقم تجهيز وتشغيل متكامل'],
                [
                    'category_id'           => $catBundles->id,
                    'category_path'         => $catBundles->path,
                    'item_type'             => 'product',
                    'profit_margin'         => 20.00,
                    'purchase_currency_id'  => $baseCurrency->id,
                    'pricing_policy'        => 'manual',
                    'min_margin_percentage' => 5.00,
                    'rounding_rule'         => 'none',
                    'base_unit_id'          => $unitPiece->id,
                    'is_active'             => true,
                    'is_composite'          => true,
                ]
            );
            $unitBundlePiece = ItemUnit::updateOrCreate(
                ['item_id' => $itemBundle->id, 'unit_id' => $unitPiece->id],
                ['conversion_factor' => 1.0, 'cost' => 128000.00, 'foreign_cost' => 0.0, 'price' => 155000.00]
            );
            ItemBarcode::updateOrCreate(['item_id' => $itemBundle->id, 'item_unit_id' => $unitBundlePiece->id], ['barcode' => 'BDL-SET-001']);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemBundle->id, 'item_unit_id' => $unitBundlePiece->id, 'price_list_id' => $retailPriceList->id], ['discount_percentage' => 0.0, 'price' => 155000.00]);
            ItemUnitPrice::updateOrCreate(['item_id' => $itemBundle->id, 'item_unit_id' => $unitBundlePiece->id, 'price_list_id' => $wholesalePriceList->id], ['discount_percentage' => 6.45, 'price' => 145000.00]);

            ItemComponent::updateOrCreate(['parent_item_id' => $itemBundle->id, 'child_item_id' => $itemDevice->id], ['quantity' => 1.0]);
            ItemComponent::updateOrCreate(['parent_item_id' => $itemBundle->id, 'child_item_id' => $itemFilter->id], ['quantity' => 2.0]);

            // =========================================================================
            // 11. الرصيد الافتتاحي بالمستودع
            // =========================================================================
            $openingStock = OpeningStock::create([
                'store_id'     => $posStore->id,
                'opening_date' => now(),
                'notes'        => 'بضاعة أول المدة الافتتاحية الشاملة',
                'user_id'      => $adminUser->id,
            ]);

            $totalInventoryValue = 0.00;

            foreach ($createdItems as $itemData) {
                $subtotal = $itemData['qty'] * $itemData['cost'];
                $totalInventoryValue += $subtotal;

                OpeningStockItem::create([
                    'opening_stock_id' => $openingStock->id,
                    'item_id'          => $itemData['item']->id,
                    'item_unit_id'     => $itemData['base_unit']->id,
                    'quantity'         => $itemData['qty'],
                    'unit_cost'        => $itemData['cost'],
                    'subtotal'         => $subtotal,
                ]);

                // تحديث رصيد صالة العرض والتسليم
                ItemStock::updateOrCreate(
                    ['item_id' => $itemData['item']->id, 'store_id' => $posStore->id],
                    ['current_quantity' => $itemData['qty'], 'reorder_level' => 10.0]
                );

                // إضافة مخزون احتياطي في المستودع الرئيسي
                ItemStock::updateOrCreate(
                    ['item_id' => $itemData['item']->id, 'store_id' => $mainStore->id],
                    ['current_quantity' => $itemData['qty'] * 2, 'reorder_level' => 20.0]
                );
            }

            // =========================================================================
            // 12. القيد الافتتاحي المتوازن محاسبياً (إجمالي المدين = إجمالي الدائن)
            // =========================================================================
            $totalCashAndBank = 500000.00 + 100000.00 + 1400000.00; // 2,000,000

            $journalEntry = JournalEntry::create([
                'entry_number' => 'JE-OPEN-' . date('Y') . '-0001',
                'entry_date'   => now()->toDateString(),
                'type'         => 'journal',
                'notes'        => 'قيد تأسيس الأرصدة الافتتاحية للمخزون والنقدية ورأس المال',
                'user_id'      => $adminUser->id,
            ]);

            $openingStock->journal_entry_id = $journalEntry->id;
            $openingStock->saveQuietly();

            // سطر 1: مدين - الخزينة الرئيسية
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id'       => $treasuryAccount->id,
                'debit'            => 500000.00,
                'credit'           => 0.00,
                'line_notes'       => 'الخزينة الرئيسية',
                'sub_ledger_type'  => Treasury::class,
                'sub_ledger_id'    => $mainTreasury->id,
            ]);

            // سطر 2: مدين - صندوق الكاشير
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id'       => $treasuryAccount->id,
                'debit'            => 100000.00,
                'credit'           => 0.00,
                'line_notes'       => 'صندوق الكاشير',
                'sub_ledger_type'  => Treasury::class,
                'sub_ledger_id'    => $posTreasury->id,
            ]);

            // سطر 3: مدين - الحساب البنكي
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id'       => $bankAccount->id,
                'debit'            => 1400000.00,
                'credit'           => 0.00,
                'line_notes'       => 'الحساب البنكي التجاري',
                'sub_ledger_type'  => Bank::class,
                'sub_ledger_id'    => $mainBank->id,
            ]);

            // سطر 4: مدين - المخزون السلعي والخامات
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id'       => $inventoryAccount->id,
                'debit'            => $totalInventoryValue,
                'credit'           => 0.00,
                'line_notes'       => 'المخزون السلعي والخامات الافتتاحية',
                'sub_ledger_type'  => Store::class,
                'sub_ledger_id'    => $posStore->id,
            ]);

            // سطر 5: دائن - رأس المال المدفوع نقداً
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id'       => $paidCapitalAccount->id,
                'debit'            => 0.00,
                'credit'           => $totalCashAndBank,
                'line_notes'       => 'رأس المال النقدي والمصرفي',
                'sub_ledger_type'  => null,
                'sub_ledger_id'    => null,
            ]);

            // سطر 6: دائن - رأس مال الأصول والمخزون
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id'       => $assetCapitalAccount->id,
                'debit'            => 0.00,
                'credit'           => $totalInventoryValue,
                'line_notes'       => 'رأس مال المخزون والخامات الافتتاحية',
                'sub_ledger_type'  => null,
                'sub_ledger_id'    => null,
            ]);
        });
    }
}