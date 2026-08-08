<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaterialConsumption\StoreMaterialConsumptionRequest;
use App\Models\MaterialConsumption;
use App\Services\MaterialConsumptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialConsumptionController extends Controller
{
    protected $consumptionService;

    public function __construct(MaterialConsumptionService $consumptionService)
    {
        $this->consumptionService = $consumptionService;
    }

    /**
     * عرض قائمة حركات استهلاك الخامات الأخيرة في الورشة مع جلب العلاقات (Eager Loading)
     */
    public function index(Request $request): JsonResponse
    {
        // التحقق من صلاحية الفني أو المستخدم لاستعراض السجل تاريخياً
        if (!$request->user()->hasPermissionTo('sale.swap_raw_materials', 'api')) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، لا تمتلك الصلاحية الكافية لاستعراض سجلات استهلاك الخامات.'
            ], 403);
        }

        $consumptions = MaterialConsumption::with(['item', 'itemUnit.unit', 'store', 'user'])
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $consumptions
        ], 200);
    }

    /**
     * استقبال وحفظ حركة استهلاك خامة فردية جديدة من شاشة الفني
     */
    public function store(StoreMaterialConsumptionRequest $request): JsonResponse
    {
        try {
            // استدعاء الخدمة الذرية لحفظ الحركة وتحديث المخازن والقيود في خطوة واحدة
            $consumption = $this->consumptionService->createConsumption(
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل حركة استهلاك الخامة وتحديث الأرصدة والقيود المالية التلقائية بنجاح.',
                'data'    => $consumption
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشلت عملية ترحيل حركة الاستهلاك: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * إعدام وحذف إذن استهلاك خامات وعكس أثره مخزنياً ومالياً بالكامل (تطهير الأشباح)
     */
    public function destroy(Request $request, MaterialConsumption $materialConsumption): JsonResponse
    {
        // التحقق من امتلاك صلاحية الحذف والعكس المالي والمخزني
        if (!$request->user()->hasPermissionTo('sale.swap_raw_materials', 'api')) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، لا تمتلك الصلاحية الكافية لحذف أو عكس مستندات الاستهلاك.'
            ], 403);
        }

        try {
            // تشغيل دالة العكس التطهيرية المخزنية والمالية من طبقة الخدمة
            $this->consumptionService->deleteConsumption($materialConsumption);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف مستند الاستهلاك وعكس حركات المخازن وتطهير القيود المالية التلقائية بنجاح.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشلت عملية الحذف والتراجع العكسي: ' . $e->getMessage()
            ], 500);
        }
    }
}
