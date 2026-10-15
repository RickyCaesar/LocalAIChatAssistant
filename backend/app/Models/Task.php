<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'status',
        'progress',
        'payload',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'progress' => 'integer',
        'payload' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
