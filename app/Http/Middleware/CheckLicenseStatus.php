<?php

namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLicenseStatus
{
    public function __construct(
        protected LicenseService $licenseService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. السماح لكافة طلبات الاستعراض والتقارير (GET, HEAD, OPTIONS)
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        // 2. قائمة المسارات المستثناة من الحظر أثناء وضع القراءة فقط
        $exemptRoutes = [
            'login',
            'logout',
            'license/activate',
            'whatsapp/webhook',
        ];

        foreach ($exemptRoutes as $exemptRoute) {
            if ($request->is("api/{$exemptRoute}") || $request->is($exemptRoute)) {
                return $next($request);
            }
        }

        // 3. التحقق من صلاحية الترخيص
        $status = $this->licenseService->getStatus();

        if ($status['is_read_only']) {
            return response()->json([
                'message' => $status['message'],
                'license_status' => $status,
            ], 402);
        }

        return $next($request);
    }
}