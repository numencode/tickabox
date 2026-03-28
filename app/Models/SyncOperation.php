<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_uuid',
        'operation',
        'payload',
        'status',
        'attempts',
        'last_error',
        'available_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'available_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
