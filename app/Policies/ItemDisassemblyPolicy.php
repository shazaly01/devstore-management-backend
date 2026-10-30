<?php

namespace App\Policies;

use App\Models\ItemDisassembly;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ItemDisassemblyPolicy
{
    use HandlesAuthorization;

    /**
     * التحقق من صلاحية عرض قائمة أذونات التفكيك.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('disassembly.view', 'api');
    }

    /**
     * التحقق من صلاحية استعراض تفاصيل إذن تفكيك محدد.
     */
    public function view(User $user, ItemDisassembly $itemDisassembly): bool
    {
        return $user->hasPermissionTo('disassembly.view', 'api');
    }

    /**
     * التحقق من صلاحية إنشاء إذن تفكيك جديد.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('disassembly.create', 'api');
    }

    /**
     * التحقق من صلاحية تعديل إذن تفكيك قائم.
     */
    public function update(User $user, ItemDisassembly $itemDisassembly): bool
    {
        return $user->hasPermissionTo('disassembly.edit', 'api') 
            || $user->hasPermissionTo('disassembly.update', 'api');
    }

    /**
     * التحقق من صلاحية حذف وإلغاء إذن تفكيك.
     */
    public function delete(User $user, ItemDisassembly $itemDisassembly): bool
    {
        return $user->hasPermissionTo('disassembly.delete', 'api');
    }
}