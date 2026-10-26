<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformancePlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
