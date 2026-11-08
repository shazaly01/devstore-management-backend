<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Http\Resources\Api\SettingResource;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    /**
     * عرض جميع الإعدادات مع إمكانية التصفية حسب التبويب (Group)
     * وإرجاعها كمصفوفة مسطحة ومصفوفة مبوبة لتسهيل بناء شاشات الـ Tabs.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $query = Setting::query();

        if ($request->filled('group')) {
            $query->where('group', $request->query('group'));
        }

        $settings = $query->orderBy('group')->orderBy('id')->get();

        return response()->json([
            'data' => SettingResource::collection($settings),
            'grouped' => SettingResource::collection($settings)->groupBy('group'),
        ]);
    }

    /**
     * جلب تفاصيل إعداد محدد بواسطة المفتاح (Key).
     */
    public function show(string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        Gate::authorize('view', $setting);

        return response()->json([
            'data' => new SettingResource($setting),
        ]);
    }

    /**
     * تحديث حزمة من الإعدادات دفعة واحدة داخل Database Transaction ذري.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        Gate::authorize('update', Setting::class);

        $settingsInput = $request->input('settings');

        DB::transaction(function () use ($settingsInput) {
            if (array_is_list($settingsInput)) {
                foreach ($settingsInput as $item) {
                    if (isset($item['key'])) {
                        Setting::set($item['key'], $item['value'] ?? null);
                    }
                }
            } else {
                foreach ($settingsInput as $key => $value) {
                    Setting::set($key, $value);
                }
            }
        });

        $allSettings = Setting::orderBy('group')->orderBy('id')->get();

        return response()->json([
            'message' => 'تم حفظ وتحديث الإعدادات بنجاح.',
            'data' => SettingResource::collection($allSettings),
            'grouped' => SettingResource::collection($allSettings)->groupBy('group'),
        ]);
    }
}