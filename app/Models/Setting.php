<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'label',
        'description',
    ];

    /**
     * المعالجة الذاتية لقيمة الإعداد وفقاً لنوعه المخزن
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if ($value === null) {
                    return null;
                }

                $type = $attributes['type'] ?? 'string';

                return match ($type) {
                    'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    'integer', 'int' => (int) $value,
                    'json', 'array' => json_decode($value, true) ?? [],
                    default => (string) $value,
                };
            },
            set: function ($value, array $attributes) {
                if ($value === null) {
                    return null;
                }

                if (is_array($value) || is_object($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }

                return (string) $value;
            }
        );
    }

    /**
     * جلب قيمة إعداد معين بواسطة المفتاح مع دعم قيمة افتراضية والتخزين المؤقت
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("app_setting_{$key}", function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting !== null ? $setting->value : $default;
        });
    }

    /**
     * تعيين أو تحديث قيمة إعداد مع تفريغ الذاكرة المؤقتة له
     */
    public static function set(string $key, mixed $value, ?string $type = null, ?string $group = null, ?string $label = null): self
    {
        $setting = static::firstOrNew(['key' => $key]);

        if ($type !== null) {
            $setting->type = $type;
        }

        if ($group !== null) {
            $setting->group = $group;
        }

        if ($label !== null) {
            $setting->label = $label;
        }

        $setting->value = $value;
        $setting->save();

        Cache::forget("app_setting_{$key}");

        return $setting;
    }

    /**
     * مسح الكاش تلقائياً عند حفظ أو حذف أي إعداد
     */
    protected static function booted(): void
    {
        static::saved(function (Setting $setting) {
            Cache::forget("app_setting_{$setting->key}");
        });

        static::deleted(function (Setting $setting) {
            Cache::forget("app_setting_{$setting->key}");
        });
    }
}