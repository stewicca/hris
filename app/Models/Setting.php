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

    /**
     * Read a setting value, falling back to the given default. Cached forever.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting:{$key}", fn () => static::query()->find($key)?->value ?? $default);
    }

    /**
     * Persist a setting value and bust its cache.
     */
    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget("setting:{$key}");
    }
}
