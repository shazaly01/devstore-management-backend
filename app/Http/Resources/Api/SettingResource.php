<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    /**
     * تحويل كائن الإعداد إلى مصفوفة استجابة مهيأة للواجهات الأمامية.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $this->value,
            'group' => $this->group,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}