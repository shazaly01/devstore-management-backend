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
use App\Http\Resources\Api\Pricing\ItemPriceHistoryMainResource;
use App\Models\ItemPriceHistory;
use App\Models\ItemPriceHistoryMain;
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
     * معاينة نتائج إعادة التسعير الجماعي قبل التطبيق
     */
    public function preview(PreviewBulkRepriceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);
        $previewData = $this->bulkRepricingService->preview($request->validated());
        return BulkRepricingPreviewResource::collection($previewData);
    }

    /**
     * تطبيق واعتماد دفعة الأسعار الجديدة وتوثيقها في رأس الدفعة وسجل التفاصيل
     */
    public function apply(ApplyBulkRepriceRequest $request): JsonResponse
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);
        $result = $this->bulkRepricingService->apply($request->validated(), Auth::id());

        return response()->json([
            'status'        => 'success',
            'message'       => 'تم تطبيق الأسعار الجديدة بنجاح وإنشاء دفعة التسعير في الأرشيف.',
            'main_id'       => $result['main_id'],
            'batch_code'    => $result['batch_code'],
            'batch_id'      => $result['batch_id'],
            'updated_count' => $result['updated_count'],
        ]);
    }

    /**
     * استعراض أرشيف مجموعات ودفعات التسعير مع الفلترة والفرز الذكي
     */
    public function batches(Request $request): AnonymousResourceCollection
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);

        $query = ItemPriceHistoryMain::query()
            ->with(['category', 'currency', 'user', 'rolledBackByUser'])
            ->latest('id');

        if ($request->filled('batch_code')) {
            $query->where('batch_code', 'like', '%' . $request->query('batch_code') . '%');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->filled('currency_id')) {
            $query->where('currency_id', $request->query('currency_id'));
        }

        if ($request->filled('change_type')) {
            $query->where('change_type', $request->query('change_type'));
        }

        if ($request->has('is_rolled_back') && $request->query('is_rolled_back') !== null && $request->query('is_rolled_back') !== '') {
            $query->where('is_rolled_back', filter_var($request->query('is_rolled_back'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('exchange_rate')) {
            $query->where('exchange_rate', $request->query('exchange_rate'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->query('to_date'));
        }

        $perPage = (int) $request->query('per_page', 15);
        return ItemPriceHistoryMainResource::collection($query->paginate($perPage));
    }

    /**
     * استعراض تفاصيل دفعة تسعير محددة مع كافة أصنافها ووحداتها
     */
    public function batchDetails(int $id): JsonResponse
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);

        $batchMain = ItemPriceHistoryMain::with([
            'category',
            'currency',
            'user',
            'rolledBackByUser',
            'details.item',
            'details.itemUnit.unit',
            'details.priceList',
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => new ItemPriceHistoryMainResource($batchMain),
        ]);
    }

    /**
     * التراجع الفوري عن مجموعة تسعير كاملة واستعادة الأسعار والتكاليف السابقة
     */
    public function rollback(RollbackPriceRequest $request): JsonResponse
    {
        $this->authorize('rollback', ItemPriceHistory::class);

        try {
            $result = $this->priceRollbackService->rollback(
                $request->validated(),
                Auth::id(),
                $request->validated('notes')
            );

            return response()->json([
                'status'              => 'success',
                'message'             => 'تم التراجع عن مجموعة التسعير بنجاح وتوثيق دفعة التراجع.',
                'main_id'             => $result['main_id'],
                'batch_code'          => $result['batch_code'],
                'rollback_main_id'    => $result['rollback_main_id'],
                'rollback_batch_code' => $result['rollback_batch_code'],
                'restored_count'      => $result['restored_count'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * استعراض سجل تاريخ تغييرات الأسعار الفردي مع الفلترة
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $this->authorize('bulkReprice', ItemPriceHistory::class);

        $query = ItemPriceHistory::query()
            ->with(['main', 'item', 'itemUnit.unit', 'priceList', 'currency', 'user'])
            ->latest('id');

        if ($request->filled('item_price_history_main_id')) {
            $query->where('item_price_history_main_id', $request->query('item_price_history_main_id'));
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->query('batch_id'));
        }

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->query('item_id'));
        }

        if ($request->filled('change_type')) {
            $query->where('change_type', $request->query('change_type'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->query('to_date'));
        }

        $perPage = (int) $request->query('per_page', 25);
        return ItemPriceHistoryResource::collection($query->paginate($perPage));
    }

    /**
     * عرض رادار الأصناف المهددة بتآكل الهامش أو الواقعة تحت الحد الأدنى
     */
    public function radar(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewRadar', ItemPriceHistory::class);
        $filters = $request->only(['category_id']);
        $radarData = $this->marginRadarService->getAtRiskItems($filters);
        return MarginRadarResource::collection($radarData);
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