<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\Api\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * تفعيل الحماية البرمجية للمتحكم بناءً على الـ Policy المعتمدة
     */
    public function __construct()
    {
        $this->authorizeResource(Category::class, 'category');
    }

    /**
     * استعراض قائمة التصنيفات مرتبة شجرياً مع تحميل الآباء لبناء المسار النصي
     */
    public function index(): AnonymousResourceCollection
    {
        // جلب التصنيفات مرتبة حسب المسار الشجري لتظهر الأبناء تحت الآباء مباشرة
        $categories = Category::with('parent.parent.parent')->orderBy('path', 'asc')->get();

        return CategoryResource::collection($categories);
    }

    /**
     * حفظ تصنيف شجري جديد في النظام
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        // إنشاء السجل (يتم احتساب الـ path تلقائياً عبر الـ Boot Method في الـ Model)
        $category = Category::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء التصنيف بنجاح.',
            'data'    => new CategoryResource($category)
        ], 201);
    }

    /**
     * عرض تفاصيل تصنيف معين
     */
    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new CategoryResource($category->load('parent.parent.parent'))
        ]);
    }

    /**
     * تعديل بيانات تصنيف قائم (تحديث تلقائي للمسار الشجري إذا تغير الأب)
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات التصنيف بنجاح.',
            'data'    => new CategoryResource($category)
        ]);
    }

    /**
     * حذف التصنيف (Soft Delete) مع تأمين القيود اللوجستية
     */
    public function destroy(Category $category): JsonResponse
    {
        // حماية هندسية: يمنع حذف التصنيف إذا كان يندرج تحته تصنيفات فرعية (أبناء)
        if ($category->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف هذا التصنيف لوجود تصنيفات فرعية تندرج تحته.'
            ], 422);
        }

        // حماية هندسية: يمنع حذف التصنيف إذا كان مربوطاً بأصناف في الدليل
        if ($category->items()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف هذا التصنيف لأنه مرتبط بأصناف مسجلة في الدليل.'
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف التصنيف بنجاح.'
        ]);
    }
}