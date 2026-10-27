<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformancePlan extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'atasan_intervensi',
        'rencana_kondisi',
        'indikator_kinerja',
        'satuan',
        'target_tahunan',
        'penanggung_jawab_nama',
        'penanggung_jawab_jabatan',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
