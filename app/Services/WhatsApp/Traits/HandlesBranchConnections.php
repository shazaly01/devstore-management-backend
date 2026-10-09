<?php

namespace App\Services\WhatsApp\Traits;

use Illuminate\Support\Facades\Config;

trait HandlesBranchConnections
{
    /**
     * ربط مفاتيح الفروع بأسماء اتصالات قاعدة البيانات
     */
    protected array $branchConnections = [
        'omd'    => 'branch_main',
        'madani' => 'branch_1',
        'port1'  => 'branch_2',
        'port2'  => 'branch_3',
    ];

    /**
     * الأسماء المترجمة للفروع للعرض على الواتساب
     */
    protected array $branchLabels = [
        'omd'    => 'المركز الرئيسي (أمدرمان)',
        'madani' => 'فرع مدني',
        'port1'  => 'فرع بورتسودان 1',
        'port2'  => 'فرع بورتسودان 2',
    ];

    /**
     * فحص الاتصالات المتاحة والمعرفة في config/database.php
     */
    protected function getAvailableBranchConnections(): array
    {
        $available = [];

        foreach ($this->branchConnections as $key => $connectionName) {
            if (Config::has("database.connections.{$connectionName}")) {
                $available[$key] = $connectionName;
            }
        }

        return $available;
    }

    /**
     * تحديد الاتصالات المستهدفة بالاستعلام بناءً على الفرع المطلوب
     */
    protected function resolveConnectionsToQuery(string $targetBranch, array $availableConnections): array
    {
        if ($targetBranch === 'all' || !isset($availableConnections[$targetBranch])) {
            return $availableConnections;
        }

        return [$targetBranch => $availableConnections[$targetBranch]];
    }

    /**
     * الحصول على التسمية النصية للفرع
     */
    protected function getBranchLabel(string $branchKey): string
    {
        return $this->branchLabels[$branchKey] ?? "فرع ({$branchKey})";
    }
}