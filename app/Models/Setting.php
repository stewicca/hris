<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    public function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::memo()->forget("setting:{$setting->key}"));
        static::deleted(fn (self $setting) => Cache::memo()->forget("setting:{$setting->key}"));
    }

    /**
     * Read a setting value, falling back to the given default. Cached forever
     * and memoized per request. Wrapping in an array ensures null values also
     * hit the cache instead of re-querying the database on every call.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::memo()->rememberForever(
            "setting:{$key}",
            fn () => ['value' => static::query()->find($key)?->value],
        );

        return is_array($cached) && array_key_exists('value', $cached)
            ? ($cached['value'] ?? $default)
            : ($cached ?? $default);
    }

    /**
     * Persist a setting value and bust its cache.
     */
    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::memo()->forget("setting:{$key}");
    }
}
