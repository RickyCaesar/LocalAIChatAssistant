<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Todo;
use App\Support\TodoEventSync;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    private function serialize(Event $event): array
    {
        $event->loadMissing(['todo:id,done', 'media:id,filename,mime', 'performancePlan:id,name,slug']);

        return [
            'id' => (int) $event->id,
            'todo_id' => $event->todo_id ? (int) $event->todo_id : null,
            'media_id' => $event->media_id ? (int) $event->media_id : null,
            'performance_plan_id' => $event->performance_plan_id ? (int) $event->performance_plan_id : null,
            'title' => (string) $event->title,
            'start_at' => $event->start_at ? $event->start_at->toIso8601String() : null,
            'end_at' => $event->end_at ? $event->end_at->toIso8601String() : null,
            'description' => $event->description,
            'location' => $event->location,
            'recurrence' => (string) ($event->recurrence ?: 'none'),
            'tindak_lanjut' => $event->tindak_lanjut,
            'durasi_jam' => $event->durasi_jam !== null ? (float) $event->durasi_jam : null,
            'done' => $event->todo ? (bool) $event->todo->done : false,
            'media' => $event->media ? [
                'id' => (int) $event->media->id,
                'filename' => $event->media->filename,
                'mime' => $event->media->mime,
            ] : null,
            'performance_plan' => $event->performancePlan ? [
                'id' => (int) $event->performancePlan->id,
                'name' => $event->performancePlan->name,
                'slug' => $event->performancePlan->slug,
            ] : null,
        ];
    }

    private function rules(bool $creating): array
    {
        return [
            'title' => $creating ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'start_at' => $creating ? 'required|date' : 'sometimes|required|date',
            'end_at' => 'nullable|date',
            'description' => 'nullable|string|max:2000',
            'location' => 'nullable|string|max:255',
            'recurrence' => 'nullable|in:none,weekly,monthly,yearly',
            'tindak_lanjut' => 'nullable|string|max:2000',
            'sync_todo' => 'sometimes|boolean',
            'done' => 'sometimes|boolean',
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('user_id', auth()->id())],
            'performance_plan_id' => ['nullable', 'integer', Rule::exists('performance_plans', 'id')],
        ];
    }

    public function index()
    {
        $events = Event::where('user_id', auth()->id())
            ->with(['todo:id,done', 'media:id,filename,mime', 'performancePlan:id,name,slug'])
            ->orderBy('start_at')
            ->get()
            ->map(fn ($e) => $this->serialize($e));

        return response()->json(['events' => $events]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $start = $this->toAppTz($data['start_at']);
        $end = isset($data['end_at']) ? $this->toAppTz($data['end_at']) : null;
        $this->assertRange($start, $end);

        $event = DB::transaction(fn () => TodoEventSync::createEvent([
            'user_id' => auth()->id(),
            'title' => $data['title'],
            'start_at' => $start,
            'end_at' => $end,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'recurrence' => $data['recurrence'] ?? 'none',
            'tindak_lanjut' => $data['tindak_lanjut'] ?? null,
            'durasi_jam' => $this->durationHours($start, $end),
            'media_id' => $data['media_id'] ?? null,
            'performance_plan_id' => $data['performance_plan_id'] ?? null,
        ], $request->boolean('sync_todo')));

        return response()->json(['event' => $this->serialize($event)], 201);
    }

    public function update(Request $request, string $id)
    {
        $event = Event::where('user_id', auth()->id())->findOrFail($id);
        $data = $request->validate($this->rules(false));
        $hasSync = $request->has('sync_todo');
        $sync = $request->boolean('sync_todo');

        if (array_key_exists('start_at', $data)) {
            $data['start_at'] = $this->toAppTz($data['start_at']);
        }
        if (array_key_exists('end_at', $data)) {
            $data['end_at'] = $data['end_at'] === null ? null : $this->toAppTz($data['end_at']);
        }

        $nextStart = $data['start_at'] ?? $event->start_at;
        $nextEnd = array_key_exists('end_at', $data) ? $data['end_at'] : $event->end_at;
        $this->assertRange($nextStart, $nextEnd);
        if (array_key_exists('start_at', $data) || array_key_exists('end_at', $data)) {
            $data['durasi_jam'] = $this->durationHours($nextStart, $nextEnd);
        }

        DB::transaction(function () use ($event, $data, $hasSync, $sync) {
            if ($event->todo_id && $hasSync && ! $sync) {
                TodoEventSync::detachEvent($event);
            }

            $event->fill(collect($data)->except(['sync_todo', 'done'])->all());
            $event->save();

            if ($hasSync && $sync && ! $event->todo_id) {
                TodoEventSync::attachTodo($event);
            }

            if (array_key_exists('done', $data) && $event->todo_id) {
                $todo = Todo::find($event->todo_id);
                if ($todo) {
                    $todo->done = $data['done'];
                    $todo->save();
                }
            }
        });

        return response()->json(['event' => $this->serialize($event->fresh())]);
    }

    private function assertRange(Carbon $start, ?Carbon $end): void
    {
        if ($end && $end->lt($start)) {
            throw ValidationException::withMessages([
                'end_at' => 'Waktu selesai tidak boleh sebelum waktu mulai.',
            ]);
        }
    }

    private function durationHours(Carbon $start, ?Carbon $end): float
    {
        if (! $end || $end->lte($start)) {
            return 1;
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    // Client mengirim ISO ber-offset (mis. ...Z). Cast datetime Laravel
    // menyimpan jam dari instance apa adanya lalu membacanya sebagai
    // config app.timezone, jadi konversi ke WITA dulu agar tidak bergeser.
    private function toAppTz(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    public function destroy(string $id)
    {
        DB::transaction(function () use ($id) {
            Event::where('user_id', auth()->id())->findOrFail($id)->delete();
        });

        return response()->json(['ok' => true]);
    }
}
