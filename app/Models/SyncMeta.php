<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMeta extends Model
{
    protected $table = 'sync_meta';

    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];

    public static function getValue(int $userId, string $key, mixed $default = null): mixed
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public static function setValue(int $userId, string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $userId, 'key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value)]
        );
    }

    public static function deleteForUser(int $userId): void
    {
        static::query()->where('user_id', $userId)->delete();
    }
}
