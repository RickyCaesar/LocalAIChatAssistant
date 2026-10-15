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
        'duration_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDurationAttribute(): ?int
    {
        if ($this->duration_seconds !== null) {
            return $this->duration_seconds;
        }
        if ($this->started_at && $this->finished_at) {
            return $this->finished_at->diffInSeconds($this->started_at);
        }

        return null;
    }
}
