<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parent_id,
            'parent_name' => $this->parent?->name,
            'name'        => $this->name,
            'path'        => $this->path,
            'full_path'   => $this->getFullPath(),
            'indent_name' => $this->getIndentName(),
            'is_active'   => (bool) $this->is_active,
            'created_at'  => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * بناء المسار النصي الكامل للتصنيف (مثال: إلكترونيات > جوالات > أجهزة أبل)
     */
    private function getFullPath(): string
    {
        $names = [];
        $current = $this->resource;
        while ($current) {
            array_unshift($names, $current->name);
            $current = $current->parent;
        }
        return implode(' > ', $names);
    }

    /**
     * بناء الاسم مع بادئة بصرية تعكس المستوى الشجري
     */
    private function getIndentName(): string
    {
        $pathTrimmed = trim($this->path ?? '', '/');
        if (empty($pathTrimmed)) {
            return $this->name;
        }

        $segments = explode('/', $pathTrimmed);
        $depth = count($segments) - 1;

        if ($depth <= 0) {
            return $this->name;
        }

        $prefix = str_repeat('│ ', $depth - 1) . '└─ ';
        return $prefix . $this->name;
    }
}