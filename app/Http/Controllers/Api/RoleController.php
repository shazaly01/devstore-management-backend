<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\Api\RoleResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct()
    {
        // استخدام authorizeResource لتطبيق الـ Policy تلقائيًا
        $this->authorizeResource(Role::class, 'role');
    }

    public function index()
    {
        $roles = Role::with('permissions')->latest()->paginate(15);
        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request)
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            $role = Role::create(['name' => $validated['name'], 'guard_name' => 'api']);
            $role->syncPermissions($validated['permissions'] ?? []);
            DB::commit();

            return new RoleResource($role->load('permissions'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create role.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Role $role)
    {
        return new RoleResource($role->load('permissions'));
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            // 1. تحديث اسم الدور دائماً بناءً على البيانات المفحوصة
            $role->update(['name' => $validated['name']]);

            // 2. التحقق الصارم: يتم تحديث الصلاحيات فقط إذا كانت متواجدة في الطلب المرسل
            if (array_key_exists('permissions', $validated)) {
                $role->syncPermissions($validated['permissions'] ?? []);
            }

            DB::commit();

            return new RoleResource($role->load('permissions'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update role.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Role $role)
    {
        $this->authorize('delete', $role);

        if (in_array($role->name, ['Super Admin', 'Admin', 'User'])) {
            abort(Response::HTTP_FORBIDDEN, 'Cannot delete default roles.');
        }

        $role->delete();

        return response()->noContent();
    }

    /**
     * جلب كافة الصلاحيات مهيكلة حسب الموديولات للواجهة الأمامية
     * دون أي تعديل على جدول الصلاحيات في قاعدة البيانات.
     */
    public function getAllPermissions()
    {
        $this->authorize('viewAny', Role::class);

        // 1. تعريف موديولات القائمة الجانبية وترتيب ظهورها
        $modulesConfig = [
            'system' => [
                'display_name' => 'لوحة التحكم والنظام',
                'groups'       => ['dashboard', 'user', 'role', 'company', 'setting', 'backup'],
            ],
            'sales' => [
                'display_name' => 'المبيعات والعملاء',
                'groups'       => ['sale', 'customer', 'pricing'],
            ],
            'purchasing' => [
                'display_name' => 'المشتريات والموردون',
                'groups'       => ['purchase', 'supplier'],
            ],
            'inventory' => [
                'display_name' => 'المخازن والأصناف',
                'groups'       => ['store', 'category', 'unit', 'item', 'disassembly', 'opening_stock', 'stock_adjustment'],
            ],
            'accounting' => [
                'display_name' => 'الحسابات والمالية',
                'groups'       => ['account', 'treasury', 'bank', 'voucher', 'expense', 'journal_entry'],
            ],
            'hr' => [
                'display_name' => 'الموارد البشرية',
                'groups'       => ['employee'],
            ],
            'reports' => [
                'display_name' => 'التقارير والرقابة',
                'groups'       => ['report'],
            ],
        ];

        // 2. قاموس ترجمة الشاشات والمجموعات
        $groupTranslations = [
            'dashboard'        => 'لوحة التحكم',
            'user'             => 'المستخدمون',
            'role'             => 'الأدوار والصلاحيات',
            'company'          => 'الشركات',
            'setting'          => 'الإعدادات العامة',
            'backup'           => 'النسخ الاحتياطي',
            'category'         => 'مجموعات الأصناف',
            'unit'             => 'وحدات القياس',
            'store'            => 'المخازن والمستودعات',
            'item'             => 'دليل الأصناف والخدمات',
            'disassembly'      => 'تفكيك وتجزئة الأصناف',
            'customer'         => 'إدارة العملاء',
            'supplier'         => 'إدارة الموردين',
            'employee'         => 'إدارة الموظفين',
            'account'          => 'شجرة الحسابات',
            'treasury'         => 'الخزائن المالية',
            'bank'             => 'الحسابات البنكية',
            'expense'          => 'بنود المصروفات',
            'purchase'         => 'فواتير المشتريات',
            'sale'             => 'فواتير المبيعات',
            'pricing'          => 'سياسات التسعير والعملات',
            'voucher'          => 'سندات القبض والصرف',
            'stock_adjustment' => 'التسويات الجردية',
            'opening_stock'    => 'بضاعة أول المدة',
            'journal_entry'    => 'القيود اليومية المركبة',
            'report'           => 'قسم التقارير الموحد',
        ];

        // 3. قاموس ترجمة الإجراءات القياسية والخاصة والتقارير
        $actionTranslations = [
            'view'                  => 'عرض',
            'create'                => 'إضافة',
            'update'                => 'تعديل',
            'delete'                => 'حذف',
            'print'                 => 'طباعة',
            'approve'               => 'اعتماد',
            'post'                  => 'ترحيل',
            'export'                => 'تصدير',
            'swap_raw_materials'    => 'ملاءمة خامات الورشة',
            'manager'               => 'إشراف المدير',
            'bulk-reprice'          => 'إعادة التسعير الجماعي',
            'view-radar'            => 'رادار تآكل الهوامش',
            'rollback'              => 'التراجع عن دفعات الأسعار',
            'manage-exchange-rates' => 'إدارة أسعار الصرف',
            'financial'             => 'التقارير المالية الإدارية',
            'inventory'             => 'التقارير المخزنية الإدارية',
            'account_statement'     => 'كشف الحساب الرئيسي',
            'sub_ledger'            => 'كشف الحساب المساعد',
            'trial_balance'         => 'ميزان المراجعة',
            'download'              => 'تحميل',
            'restore'               => 'استعادة',
        ];

        // خريطة عكسية سريعة لمعرفة الموديول التابع له كل groupKey
        $groupToModuleMap = [];
        foreach ($modulesConfig as $modKey => $config) {
            foreach ($config['groups'] as $grp) {
                $groupToModuleMap[$grp] = $modKey;
            }
        }

        // 4. جلب كافة صلاحيات الـ API وتجميعها حسب الشاشة (groupKey)
        $allPermissions = Permission::where('guard_name', 'api')->orderBy('id')->get();
        $groupedByScreen = [];

        foreach ($allPermissions as $permission) {
            $parts = explode('.', $permission->name, 2);
            $groupKey = count($parts) === 2 ? $parts[0] : 'general';
            $actionKey = count($parts) === 2 ? $parts[1] : $permission->name;

            $groupedByScreen[$groupKey][] = [
                'id'             => $permission->id,
                'name'           => $permission->name,
                'action'         => $actionKey,
                'action_display' => $actionTranslations[$actionKey] ?? $actionKey,
            ];
        }

        // 5. بناء شجرة الموديولات النهائية
        $structuredModules = [];

        foreach ($modulesConfig as $modKey => $config) {
            $moduleGroups = [];

            foreach ($config['groups'] as $groupKey) {
                if (!empty($groupedByScreen[$groupKey])) {
                    $moduleGroups[] = [
                        'key'          => $groupKey,
                        'display_name' => $groupTranslations[$groupKey] ?? $groupKey,
                        'permissions'  => $groupedByScreen[$groupKey],
                    ];
                    unset($groupedByScreen[$groupKey]);
                }
            }

            if (!empty($moduleGroups)) {
                $structuredModules[] = [
                    'key'          => $modKey,
                    'display_name' => $config['display_name'],
                    'groups'       => $moduleGroups,
                ];
            }
        }

        // 6. احتواء تلقائي لأي صلاحيات إضافية في الإنتاج غير مدرجة في الخريطة أعلاه
        if (!empty($groupedByScreen)) {
            $otherGroups = [];
            foreach ($groupedByScreen as $groupKey => $perms) {
                $otherGroups[] = [
                    'key'          => $groupKey,
                    'display_name' => $groupTranslations[$groupKey] ?? $groupKey,
                    'permissions'  => $perms,
                ];
            }

            $structuredModules[] = [
                'key'          => 'other',
                'display_name' => 'صلاحيات إضافية',
                'groups'       => $otherGroups,
            ];
        }

        $allActions = collect($actionTranslations)->map(function ($displayName, $key) {
            return ['key' => $key, 'display' => $displayName];
        })->values();

        return response()->json([
            'modules' => $structuredModules,
            'actions' => $allActions,
        ]);
    }
}