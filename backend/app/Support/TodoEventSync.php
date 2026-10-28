<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Todo;
use Illuminate\Validation\ValidationException;

class TodoEventSync
{
    public static function createTodo(array $data, bool $withEvent = false): Todo
    {
        $todo = Todo::create($data);

        if ($withEvent) {
            self::attachEvent($todo);
        }

        return $todo;
    }

    public static function createEvent(array $data, bool $withTodo = false): Event
    {
        $event = Event::create($data);

        if ($withTodo) {
            self::attachTodo($event);
        }

        return $event;
    }

    public static function attachEvent(Todo $todo): Event
    {
        if (! $todo->due_at) {
            throw ValidationException::withMessages([
                'due_at' => 'Tanggal jatuh tempo wajib diisi untuk menampilkan todo di kalender.',
            ]);
        }

        $event = Event::create([
            'user_id' => $todo->user_id,
            'title' => $todo->title,
            'start_at' => $todo->due_at,
            'end_at' => $todo->due_at->copy()->addMinutes(Todo::durationMinutes($todo->durasi_jam)),
            'description' => $todo->notes,
            'durasi_jam' => $todo->durasi_jam ?: 1,
            'tindak_lanjut' => $todo->tindak_lanjut,
            'media_id' => $todo->media_id,
            'media_ids' => $todo->media_ids,
            'performance_plan_id' => $todo->performance_plan_id,
        ]);

        $todo->event_id = $event->id;
        $todo->saveQuietly();

        $event->todo_id = $todo->id;
        $event->saveQuietly();

        self::copyPlans($todo, $event);

        return $event;
    }

    public static function attachTodo(Event $event): Todo
    {
        $seconds = $event->end_at
            ? $event->start_at->diffInSeconds($event->end_at, false)
            : 3600;
        $duration = $event->durasi_jam
            ?: ($seconds > 0 ? max(0.25, round($seconds / 3600, 2)) : 1);

        $todo = Todo::create([
            'user_id' => $event->user_id,
            'title' => $event->title,
            'done' => false,
            'due_at' => $event->start_at,
            'priority' => 'sedang',
            'notes' => $event->description,
            'durasi_jam' => $duration,
            'tindak_lanjut' => $event->tindak_lanjut,
            'media_id' => $event->media_id,
            'media_ids' => $event->media_ids,
            'performance_plan_id' => $event->performance_plan_id,
        ]);

        $event->todo_id = $todo->id;
        $event->saveQuietly();

        $todo->event_id = $event->id;
        $todo->saveQuietly();

        self::copyPlans($event, $todo);

        return $todo;
    }

    public static function syncPlans(Todo|Event $model, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $model->performancePlans()->sync($ids);

        $other = $model instanceof Todo
            ? ($model->event_id ? Event::find($model->event_id) : null)
            : ($model->todo_id ? Todo::find($model->todo_id) : null);

        if ($other) {
            $other->performancePlans()->sync($ids);
        }
    }

    private static function copyPlans(Todo|Event $from, Todo|Event $to): void
    {
        $to->performancePlans()->sync($from->performancePlans()->pluck('performance_plans.id')->all());
    }

    public static function detachTodo(Todo $todo): void
    {
        $event = $todo->event_id ? Event::find($todo->event_id) : null;

        if ($todo->event_id) {
            $todo->event_id = null;
            $todo->saveQuietly();
        }

        if ($event) {
            $event->todo_id = null;
            $event->saveQuietly();
            $event->delete();
        }
    }

    public static function detachEvent(Event $event): void
    {
        $todo = $event->todo_id ? Todo::find($event->todo_id) : null;

        if ($event->todo_id) {
            $event->todo_id = null;
            $event->saveQuietly();
        }

        if ($todo) {
            $todo->event_id = null;
            $todo->saveQuietly();
            $todo->delete();
        }
    }
}
