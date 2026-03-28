<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Todo extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'remote_id',
        'title',
        'is_completed',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'is_completed'     => 'boolean',
        'last_modified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Todo $todo) {
            $todo->uuid ??= (string) Str::uuid();
            $todo->last_modified_at ??= now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
