<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportCustomersRequest;
use App\Http\Requests\Import\ImportSuppliersRequest;
use App\Http\Requests\Import\ImportItemsRequest;
use App\Services\ImportDataService;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    protected ImportDataService $importDataService;

    public function __construct(ImportDataService $importDataService)
    {
        $this->importDataService = $importDataService;
    }

    /**
     * استيراد قائمة العملاء وأرصدتهم الافتتاحية
     */
    public function importCustomers(ImportCustomersRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $result = $this->importDataService->importCustomers($request->validated()['customers']);

        return response()->json([
            'success' => true,
            'message' => "تم استيراد {$result['imported_count']} عميل بنجاح وتحديث أرصدتهم الافتتاحية.",
            'data'    => $result,
        ], 200);
    }

    /**
     * استيراد قائمة الموردين وأرصدتهم الافتتاحية
     */
    public function importSuppliers(ImportSuppliersRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $result = $this->importDataService->importSuppliers($request->validated()['suppliers']);

        return response()->json([
            'success' => true,
            'message' => "تم استيراد {$result['imported_count']} مورد بنجاح وتحديث أرصدتهم الافتتاحية.",
            'data'    => $result,
        ], 200);
    }

    /**
     * استيراد قائمة الأصناف ووحداتها وأسعارها وباركوداتها
     */
    public function importItems(ImportItemsRequest $request): JsonResponse
    {
        $this->authorize('create', Item::class);

        $result = $this->importDataService->importItems($request->validated()['items']);

        return response()->json([
            'success' => true,
            'message' => "تم استيراد {$result['imported_count']} صنف بنجاح وإعداد مصفوفات الأسعار والوحدات.",
            'data'    => $result,
        ], 200);
    }
}