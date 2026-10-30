<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemDisassembly;
use App\Services\ItemDisassemblyService;
use App\Http\Requests\ItemDisassembly\StoreItemDisassemblyRequest;
use App\Http\Requests\ItemDisassembly\UpdateItemDisassemblyRequest;
use App\Http\Resources\Api\ItemDisassemblyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Exception;

class ItemDisassemblyController extends Controller
{
    protected ItemDisassemblyService $disassemblyService;

    public function __construct(ItemDisassemblyService $disassemblyService)
    {
        $this->disassemblyService = $disassemblyService;
        $this->authorizeResource(ItemDisassembly::class, 'item_disassembly');
    }

    /**
     * استعراض قائمة أذونات التفكيك مع تطبيق التصفية والبحث النصي والتحميل المسبق للعلاقات
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ItemDisassembly::with([
            'store',
            'sourceItem',
            'sourceItemUnit.unit',
            'items.item',
            'items.itemUnit.unit',
            'user'
        ]);

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->store_id);
        }

        if ($request->filled('source_item_id')) {
            $query->where('source_item_id', (int) $request->source_item_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('disassembly_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('disassembly_date', '<=', $request->to_date);
        }

        $disassemblies = $query->latest('disassembly_date')
            ->latest('id')
            ->paginate(15);

        return ItemDisassemblyResource::collection($disassemblies);
    }

    /**
     * حفظ إذن تفكيك جديد وتحديث المخازن وتكاليف الأصناف فوراً
     */
    public function store(StoreItemDisassemblyRequest $request): JsonResponse
    {
        try {
            $disassembly = $this->disassemblyService->createDisassembly(
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ إذن التفكيك وصرف الخام وتوريد الأجزاء الناتجة وتحديث تكاليفها بنجاح.',
                'data'    => new ItemDisassemblyResource($disassembly)
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشلت عملية حفظ إذن التفكيك: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * عرض تفاصيل إذن تفكيك محدد مع أسطر مخرجاته وبيانات الصنف المصروف
     */
    public function show(ItemDisassembly $itemDisassembly): JsonResponse
    {
        $itemDisassembly->load([
            'store',
            'sourceItem',
            'sourceItemUnit.unit',
            'items.item',
            'items.itemUnit.unit',
            'user'
        ]);

        return response()->json([
            'success' => true,
            'data'    => new ItemDisassemblyResource($itemDisassembly)
        ]);
    }

    /**
     * تعديل إذن تفكيك قائم وإعادة موازنة المخازن والتكاليف
     */
    public function update(UpdateItemDisassemblyRequest $request, ItemDisassembly $itemDisassembly): JsonResponse
    {
        try {
            $updatedDisassembly = $this->disassemblyService->updateDisassembly(
                $itemDisassembly,
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل إذن التفكيك وإعادة وزن المخزون وتكاليف النواتج بنجاح.',
                'data'    => new ItemDisassemblyResource($updatedDisassembly)
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشلت عملية تحديث إذن التفكيك: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * إلغاء وحذف إذن التفكيك وعكس كافة الحركات المخزنية المترتبة عليه
     */
    public function destroy(ItemDisassembly $itemDisassembly): JsonResponse
    {
        try {
            $this->disassemblyService->deleteDisassembly($itemDisassembly);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف إذن التفكيك وعكس الحركات المخزنية للصنف الخام والأجزاء الناتجة بنجاح.'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشلت عملية حذف إذن التفكيك: ' . $e->getMessage()
            ], 500);
        }
    }
}