<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'role',
        'kind',
        'filename',
        'mime',
        'size',
        'path',
        'preview',
        'model',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }
}