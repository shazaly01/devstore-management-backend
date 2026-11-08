<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemLicense extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'system_licenses';

    protected $fillable = [
        'client_name',
        'license_key',
        'expires_at',
        'max_seen_at',
        'last_verified_at',
        'is_active',
        'meta_data',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'max_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'meta_data' => 'array',
        ];
    }
}