<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PreviewBulkRepriceRequest;
use App\Http\Requests\Pricing\ApplyBulkRepriceRequest;
use App\Http\Requests\Pricing\RollbackPriceRequest;
use App\Http\Requests\Pricing\PreviewForeignCostRequest;
use App\Http\Requests\Pricing\ApplyForeignCostRequest;
use App\Http\Resources\Api\Pricing\BulkRepricingPreviewResource;
use App\Http\Resources\Api\Pricing\MarginRadarResource;
use App\Http\Resources\Api\Pricing\ItemPriceHistoryResource;
use App\Models\ItemPriceHistory;
use App\Services\Pricing\BulkRepricingService;
use App\Services\Pricing\MarginRadarService;
use App\Services\Pricing\PriceRollbackService;
use App\Services\Pricing\ForeignCostSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Exception;

class PricingController extends Controller
{
    public function __construct(
        protected BulkRepricingService $bulkRepricingService,
        protected MarginRadarService $marginRadarService,
        protected PriceRollbackService $priceRollbackService,
        protected ForeignCostSetupService $foreignCostSetupService
    ) {}

    /**
     * معاينة نتائج إعادة التسعير الجماعي قبل التطبيق[cite: 12]
     */
    public function preview(PreviewBulkRepriceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class); //[cite: 12]
        $previewData = $this->bulkRepricingService->preview($request->validated()); //[cite: 12]
        return BulkRepricingPreviewResource::collection($previewData); //[cite: 12]
    }

    /**
     * تطبيق واعتماد دفعة الأسعار الجديدة وتوثيقها في السجل[cite: 12]
     */
    public function apply(ApplyBulkRepriceRequest $request): JsonResponse
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class); //[cite: 12]
        $result = $this->bulkRepricingService->apply($request->validated(), Auth::id()); //[cite: 12]

        return response()->json([
            'status'        => 'success',
            'message'       => 'تم تطبيق الأسعار الجديدة بنجاح وتوثيق العملية في سجل التاريخ.',
            'batch_id'      => $result['batch_id'],
            'updated_count' => $result['updated_count'],
        ]); //[cite: 12]
    }

    /**
     * عرض رادار الأصناف المهددة بتآكل الهامش أو الواقعة تحت الحد الأدنى[cite: 12]
     */
    public function radar(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewRadar', ItemPriceHistory::class); //[cite: 12]
        $filters = $request->only(['category_id']); //[cite: 12]
        $radarData = $this->marginRadarService->getAtRiskItems($filters); //[cite: 12]
        return MarginRadarResource::collection($radarData); //[cite: 12]
    }

    /**
     * التراجع الفوري عن دفعة تسعير كاملة واستعادة الأسعار السابقة[cite: 12]
     */
    public function rollback(RollbackPriceRequest $request): JsonResponse
    {
        $this->authorize('rollback', ItemPriceHistory::class); //[cite: 12]

        try {
            $result = $this->priceRollbackService->rollback(
                $request->validated('batch_id'),
                Auth::id(),
                $request->validated('notes')
            ); //[cite: 12]

            return response()->json([
                'status'            => 'success',
                'message'           => 'تم التراجع عن دفعة التسعير واستعادة الأسعار السابقة بنجاح.',
                'rollback_batch_id' => $result['rollback_batch_id'],
                'original_batch_id' => $result['original_batch_id'],
                'restored_count'    => $result['restored_count'],
            ]); //[cite: 12]
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422); //[cite: 12]
        }
    }

    /**
     * استعراض سجل تاريخ تغييرات الأسعار مع الفلترة[cite: 12]
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class); //[cite: 12]

        $query = ItemPriceHistory::query()
            ->with(['item', 'itemUnit.unit', 'priceList', 'currency', 'user'])
            ->latest('id'); //[cite: 12]

        if ($request->filled('batch_id')) $query->where('batch_id', $request->query('batch_id')); //[cite: 12]
        if ($request->filled('item_id')) $query->where('item_id', $request->query('item_id')); //[cite: 12]
        if ($request->filled('change_type')) $query->where('change_type', $request->query('change_type')); //[cite: 12]
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->query('from_date')); //[cite: 12]
        if ($request->filled('to_date')) $query->whereDate('created_at', '<=', $request->query('to_date')); //[cite: 12]

        $perPage = $request->query('per_page', 25); //[cite: 12]
        return ItemPriceHistoryResource::collection($query->paginate($perPage)); //[cite: 12]
    }

    /**
     * معاينة توليد التكلفة الأجنبية التأسيسية
     */
    public function previewForeignCost(PreviewForeignCostRequest $request): JsonResponse
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);

        $preview = $this->foreignCostSetupService->preview($request->validated());

        return response()->json([
            'status' => 'success',
            'data'   => $preview,
        ]);
    }

    /**
     * اعتماد وحفظ التكلفة الأجنبية وربط العملة المرجعية للأصناف
     */
    public function applyForeignCost(ApplyForeignCostRequest $request): JsonResponse
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);

        $result = $this->foreignCostSetupService->apply($request->validated());

        return response()->json([
            'status'              => 'success',
            'message'             => 'تم تثبيت التكلفة بالعملة الأجنبية وربط العملة المرجعية بنجاح.',
            'updated_units_count' => $result['updated_units_count'],
            'updated_items_count' => $result['updated_items_count'],
        ]);
    }
}