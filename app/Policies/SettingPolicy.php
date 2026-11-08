<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SettingPolicy
{
    use HandlesAuthorization;

    /**
     * التحقق من صلاحية استعراض قائمة الإعدادات.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('setting.view');
    }

    /**
     * التحقق من صلاحية عرض إعداد محدد.
     */
    public function view(User $user, Setting $setting): bool
    {
        return $user->hasPermissionTo('setting.view');
    }

    /**
     * التحقق من صلاحية تعديل وحفظ حزمة الإعدادات.
     */
    public function update(User $user): bool
    {
        return $user->hasPermissionTo('setting.update');
    }
}