<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Media;
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
        $event->loadMissing(['todo:id,done', 'media:id,filename,mime', 'performancePlan:id,name,slug', 'performancePlans:id,name,slug,indikator_kinerja,satuan']);
        $mediaItems = $this->mediaItems($event);
        $planIds = $event->performancePlans->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($planIds === [] && $event->performance_plan_id) {
            $planIds = [(int) $event->performance_plan_id];
        }

        return [
            'id' => (int) $event->id,
            'todo_id' => $event->todo_id ? (int) $event->todo_id : null,
            'media_id' => $event->media_id ? (int) $event->media_id : null,
            'media_ids' => $this->mediaIdsOf($event),
            'performance_plan_id' => $event->performance_plan_id ? (int) $event->performance_plan_id : null,
            'performance_plan_ids' => $planIds,
            'performance_plans' => $event->performancePlans->map(fn ($plan) => [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'indikator_kinerja' => $plan->indikator_kinerja,
                'satuan' => $plan->satuan,
            ])->values()->all(),
            'title' => (string) $event->title,
            'start_at' => $event->start_at ? $event->start_at->toIso8601String() : null,
            'end_at' => $event->end_at ? $event->end_at->toIso8601String() : null,
            'description' => $event->description,
            'location' => $event->location,
            'recurrence' => (string) ($event->recurrence ?: 'none'),
            'tindak_lanjut' => $event->tindak_lanjut,
            'durasi_jam' => $event->durasi_jam !== null ? (float) $event->durasi_jam : null,
            'done' => $event->todo ? (bool) $event->todo->done : false,
            'media' => $mediaItems[0] ?? null,
            'media_items' => $mediaItems,
            'performance_plan' => $event->performancePlan ? [
                'id' => (int) $event->performancePlan->id,
                'name' => $event->performancePlan->name,
                'slug' => $event->performancePlan->slug,
            ] : null,
        ];
    }

    private function rules(bool $creating): array
    {
        $plan = Rule::exists('performance_plans', 'id')->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', auth()->id()));

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
            'media_ids' => 'sometimes|array',
            'media_ids.*' => ['integer', Rule::exists('media', 'id')->where('user_id', auth()->id())],
            'performance_plan_id' => ['nullable', 'integer', $plan],
            'performance_plan_ids' => 'sometimes|array',
            'performance_plan_ids.*' => ['integer', $plan],
        ];
    }

    public function index()
    {
        $events = Event::where('user_id', auth()->id())
            ->with(['todo:id,done', 'media:id,filename,mime', 'performancePlan:id,name,slug', 'performancePlans:id,name,slug,indikator_kinerja,satuan'])
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
        $planIds = $this->planIds($data);
        $mediaIds = $this->mediaIdsFromData($data);

        $event = DB::transaction(function () use ($data, $start, $end, $planIds, $mediaIds, $request) {
            $event = TodoEventSync::createEvent([
                'user_id' => auth()->id(),
                'title' => $data['title'],
                'start_at' => $start,
                'end_at' => $end,
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'recurrence' => $data['recurrence'] ?? 'none',
                'tindak_lanjut' => $data['tindak_lanjut'] ?? null,
                'durasi_jam' => $this->durationHours($start, $end),
                'media_id' => $mediaIds[0] ?? null,
                'media_ids' => $mediaIds ?: null,
                'performance_plan_id' => $planIds[0] ?? null,
            ], $request->boolean('sync_todo'));

            TodoEventSync::syncPlans($event, $planIds);

            return $event;
        });

        return response()->json(['event' => $this->serialize($event)], 201);
    }

    private function planIds(array $data): array
    {
        if (array_key_exists('performance_plan_ids', $data)) {
            return array_values(array_unique(array_filter(array_map('intval', $data['performance_plan_ids'] ?? []))));
        }

        return ! empty($data['performance_plan_id']) ? [(int) $data['performance_plan_id']] : [];
    }

    private function mediaIdsFromData(array $data): array
    {
        if (array_key_exists('media_ids', $data)) {
            return array_values(array_unique(array_filter(array_map('intval', $data['media_ids'] ?? []))));
        }

        return ! empty($data['media_id']) ? [(int) $data['media_id']] : [];
    }

    private function mediaIdsOf(Event $event): array
    {
        $ids = is_array($event->media_ids) ? $event->media_ids : [];
        if ($ids === [] && $event->media_id) {
            $ids = [(int) $event->media_id];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function mediaItems(Event $event): array
    {
        $ids = $this->mediaIdsOf($event);
        if ($ids === []) {
            return [];
        }

        // shortcut: satu query media per item; pindah ke pivot/eager load bila datanya besar.
        $media = Media::whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(function (int $id) use ($media) {
            $item = $media->get($id);

            return $item ? [
                'id' => (int) $item->id,
                'filename' => $item->filename,
                'mime' => $item->mime,
            ] : null;
        }, $ids)));
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
        if (array_key_exists('media_ids', $data) || array_key_exists('media_id', $data)) {
            $mediaIds = $this->mediaIdsFromData($data);
            $data['media_id'] = $mediaIds[0] ?? null;
            $data['media_ids'] = $mediaIds ?: null;
        }

        DB::transaction(function () use ($event, $data, $hasSync, $sync) {
            if ($event->todo_id && $hasSync && ! $sync) {
                TodoEventSync::detachEvent($event);
            }

            $planIds = null;
            if (array_key_exists('performance_plan_ids', $data) || array_key_exists('performance_plan_id', $data)) {
                $planIds = $this->planIds($data);
                $event->performance_plan_id = $planIds[0] ?? null;
            }

            $event->fill(collect($data)->except(['sync_todo', 'done', 'performance_plan_ids'])->all());
            $event->save();

            if ($planIds !== null) {
                TodoEventSync::syncPlans($event, $planIds);
            }

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
