<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\License\ActivateLicenseRequest;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;

class LicenseController extends Controller
{
    public function __construct(
        protected LicenseService $licenseService
    ) {}

    /**
     * جلب تقرير حالة الترخيص الحالية للنظام.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->licenseService->getStatus());
    }

    /**
     * تفعيل وتثبيت ترخيص جديد في النظام.
     */
    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        $result = $this->licenseService->activateLicense(
            $request->validated('license_key')
        );

        return response()->json($result);
    }
}