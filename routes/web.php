<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Api\PublicInvoiceController;

/*
|--------------------------------------------------------------------------
| Web Routes - SPA Frontend Handler
|--------------------------------------------------------------------------
*/

// مسار استعراض وتحميل الفاتورة العام والمباشر للعميل عبر الواتساب (بدون تسجيل دخول)
Route::get('/invoices/v/{uuid}', [PublicInvoiceController::class, 'show'])
    ->name('invoices.public.show');

// مسار المصيد العام (Catch-All Route) واشتراط استثناء الـ API وروابط الفواتير والـ Health Check
Route::any('{any}', function () {
    $indexPath = public_path('index.html');

    // التحقق من وجود ملف بناء الواجهة لتجنب أخطاء السيرفر البيضاء
    if (File::exists($indexPath)) {
        return File::get($indexPath);
    }

    return response()->json([
        'status' => false,
        'message' => 'Frontend build files (index.html) are missing in the public directory. Please run npm run build on your frontend project.',
    ], 404);
})->where('any', '^(?!api|up|invoices).*$');