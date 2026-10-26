<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    protected $fillable = [
        'user_id',
        'event_id',
        'media_id',
        'performance_plan_id',
        'title',
        'done',
        'due_at',
        'priority',
        'notes',
        'durasi_jam',
        'tindak_lanjut',
    ];

    protected $casts = [
        'done' => 'boolean',
        'due_at' => 'datetime',
        'durasi_jam' => 'float',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Todo $todo) {
            if ($todo->event_id) {
                Event::withoutEvents(fn () => Event::whereKey($todo->event_id)->delete());
            }
        });

        static::updated(function (Todo $todo) {
            if (! $todo->event_id) {
                return;
            }

            $event = Event::find($todo->event_id);
            if (! $event) {
                return;
            }

            $event->title = $todo->title;
            $event->tindak_lanjut = $todo->tindak_lanjut;
            $event->media_id = $todo->media_id;
            $event->performance_plan_id = $todo->performance_plan_id;

            if ($todo->wasChanged(['due_at', 'durasi_jam'])) {
                $event->durasi_jam = $todo->durasi_jam ?: 1;
                if ($todo->due_at) {
                    $event->start_at = $todo->due_at;
                    $event->end_at = $todo->due_at->copy()->addMinutes(self::durationMinutes($todo->durasi_jam));
                }
            }

            $event->saveQuietly();
        });
    }

    public static function durationMinutes(?float $hours): int
    {
        return max(15, (int) round(($hours ?: 1) * 60));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function performancePlan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class);
    }
}
