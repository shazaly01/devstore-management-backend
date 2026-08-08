<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Api\CustomerResource;
use App\Models\Customer;
use App\Models\Account;
use App\Services\SubLedgerOpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    protected SubLedgerOpeningBalanceService $openingBalanceService;

    public function __construct(SubLedgerOpeningBalanceService $openingBalanceService)
    {
        $this->openingBalanceService = $openingBalanceService;

        // تطبيق حماية الصلاحيات التلقائية عبر الـ Policy المرتبط بالموديل
        $this->authorizeResource(Customer::class, 'customer');
    }

    /**
     * عرض قائمة العملاء مع حساب الأرصدة الفعلية برمجياً وتحميل فئة الأسعار
     */
    public function index(): JsonResponse
    {
        // [تحديث]: تم تضمين 'priceList' في الـ Eager Loading لشحن الفئة فوراً للفرونت إند
        $customers = Customer::with(['account', 'priceList'])
                             ->withSum('journalLines', 'debit')
                             ->withSum('journalLines', 'credit')
                             ->latest()
                             ->get();

        return response()->json([
            'success' => true,
            'data'    => CustomerResource::collection($customers)
        ]);
    }

    /**
     * إنشاء عميل جديد ومزامنة قيد الرصيد الافتتاحي الصامت بالتزامن داخل الـ Transaction
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        return DB::transaction(function () use ($validatedData) {
            // جلب حساب العملاء الإجمالي الثابت من شجرة الحسابات
            $parentAccount = Account::where('code', Account::CODE_CUSTOMERS)->firstOrFail();

            // 1. إنشاء سجل العميل أولاً وحقن معرف فئة الأسعار المحددة له للتأسيس
            $customer = Customer::create([
                'name'            => $validatedData['name'],
                'phone'           => $validatedData['phone'] ?? null,
                'email'           => $validatedData['email'] ?? null,
                'credit_limit'    => $validatedData['credit_limit'] ?? 0.00,
                'account_id'      => $parentAccount->id,
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
                'price_list_id'   => $validatedData['price_list_id'] ?? null, // [الإضافة الحالية]: تخزين فئة السعر المستهدفة
            ]);

            // 2. استدعاء السرفيس لتوليد القيد المدين الموازن وتحديث أرصدة الشجرة والعميل
            if (isset($validatedData['opening_balance']) && (float)$validatedData['opening_balance'] > 0) {
                $this->openingBalanceService->syncOpeningBalance($customer, (float)$validatedData['opening_balance']);
            }

            // [تحديث]: تحميل علاقة فئة السعر لعرض البيانات مكتملة في الاستجابة
            $customer->load(['account', 'priceList']);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء سجل العميل ومزامنة رصيده الافتتاحي بنجاح.',
                'data'    => new CustomerResource($customer)
            ], 201);
    });
    }

    /**
     * عرض تفاصيل عميل محدد
     */
    public function show(Customer $customer): JsonResponse
    {
        // [تحديث]: تحميل علاقة فئة السعر للعميل المستهدف
        $customer->load(['account', 'priceList'])
                 ->loadSum('journalLines', 'debit')
                 ->loadSum('journalLines', 'credit');

        return response()->json([
            'success' => true,
            'data'    => new CustomerResource($customer)
        ]);
    }

    /**
     * تحديث بيانات العميل وإعادة صياغة قيد الرصيد الافتتاحي تلقائياً عند التعديل
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $validatedData = $request->validated();

        return DB::transaction(function () use ($validatedData, $customer) {
            // 1. تحديث البيانات الأساسية والائتمانية والتبديل بين فئات الأسعار (مثال ترقية من B إلى A)
            $customer->update([
                'name'          => $validatedData['name'],
                'phone'         => $validatedData['phone'] ?? $customer->phone,
                'email'         => $validatedData['email'] ?? $customer->email,
                'credit_limit'  => $validatedData['credit_limit'] ?? $customer->credit_limit,
                'price_list_id' => $validatedData['price_list_id'] ?? $customer->price_list_id, // [التحديث الحالي]: تعديل فئة السعر
            ]);

            // 2. مزامنة الرصيد الافتتاحي المضمن (استراتيجية مسح الأثر المحاسبي القديم وإعادة البناء)
            if (isset($validatedData['opening_balance'])) {
                $this->openingBalanceService->syncOpeningBalance($customer, (float)$validatedData['opening_balance']);
            }

            // [تحديث]: إعادة تحميل الكائن بالروابط وفئة الأسعار المحدثة لوزن مصفوفة الـ JSON المرسلة
            $customer->load(['account', 'priceList'])
                     ->loadSum('journalLines', 'debit')
                     ->loadSum('journalLines', 'credit');

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث بيانات العميل بنجاح وإعادة احتساب الرصيد الافتتاحي.',
                'data'    => new CustomerResource($customer)
            ]);
        });
    }

    /**
     * حذف سجل العميل ناعماً وعكس أثره المالي الافتتاحي بالكامل من الدفاتر الماليّة قبل الحذف
     */
    public function destroy(Customer $customer): JsonResponse
    {
        return DB::transaction(function () use ($customer) {
            // عكس وتصفير الأثر المالي للقيد الصامت المرتبط بالعميل من النظام قبل حذف السجل
            $this->openingBalanceService->syncOpeningBalance($customer, 0.00);

            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم حذف سجل العميل وعكس أثره المالي من النظام تماماً.'
            ]);
        });
    }
}
