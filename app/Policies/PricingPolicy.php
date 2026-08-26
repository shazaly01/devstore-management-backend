<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PricingPolicy
{
    use HandlesAuthorization;

    /**
     * التحقق من صلاحية معاينة وتطبيق إعادة التسعير الجماعي
     */
    public function bulkReprice(User $user): bool
    {
        return $user->hasPermissionTo('pricing.bulk-reprice');
    }

    /**
     * التحقق من صلاحية استعراض رادار تآكل الهوامش
     */
    public function viewRadar(User $user): bool
    {
        return $user->hasPermissionTo('pricing.view-radar');
    }

    /**
     * التحقق من صلاحية التراجع عن دفعات الأسعار
     */
    public function rollback(User $user): bool
    {
        return $user->hasPermissionTo('pricing.rollback');
    }

    /**
     * التحقق من صلاحية تسجيل أسعار الصرف اليومية
     */
    public function manageExchangeRates(User $user): bool
    {
        return $user->hasPermissionTo('pricing.manage-exchange-rates');
    }
}