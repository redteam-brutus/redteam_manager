<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const TIMEZONE_KEY = 'app.timezone';

    private const CACHE_PREFIX = 'app-setting:';

    private const CACHE_TTL = 3600;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'json',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL,
            fn (): mixed => self::query()->where('key', $key)->value('value'),
        );

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::query()->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    public static function timezone(): string
    {
        $tz = self::get(self::TIMEZONE_KEY);

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.timezone', 'UTC');
    }
}
