<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = [
        'user_id',
        'todo_id',
        'media_id',
        'performance_plan_id',
        'title',
        'start_at',
        'end_at',
        'description',
        'location',
        'recurrence',
        'tindak_lanjut',
        'durasi_jam',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'durasi_jam' => 'float',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Event $event) {
            if ($event->todo_id) {
                Todo::withoutEvents(fn () => Todo::whereKey($event->todo_id)->delete());
            }
        });

        static::updated(function (Event $event) {
            if (! $event->todo_id) {
                return;
            }

            $todo = Todo::find($event->todo_id);
            if (! $todo) {
                return;
            }

            $todo->title = $event->title;
            $todo->tindak_lanjut = $event->tindak_lanjut;
            $todo->media_id = $event->media_id;
            $todo->performance_plan_id = $event->performance_plan_id;

            if ($event->wasChanged('start_at')) {
                $todo->due_at = $event->start_at;
            }
            if ($event->wasChanged(['start_at', 'end_at', 'durasi_jam']) && $event->durasi_jam !== null) {
                $todo->durasi_jam = $event->durasi_jam;
            } elseif ($event->wasChanged(['start_at', 'end_at']) && $event->start_at && $event->end_at) {
                $minutes = $event->start_at->diffInMinutes($event->end_at, false);
                if ($minutes > 0) {
                    $todo->durasi_jam = round($minutes / 60, 2);
                }
            }

            $todo->saveQuietly();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
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
