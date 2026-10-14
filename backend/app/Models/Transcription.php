<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcription extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'filename',
        'path',
        'text',
        'language',
        'duration',
        'model',
        'error',
    ];

    protected $casts = [
        'duration' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
