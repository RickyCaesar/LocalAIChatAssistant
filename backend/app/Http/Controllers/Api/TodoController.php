<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Todo;
use App\Support\TodoEventSync;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TodoController extends Controller
{
    private function serialize(Todo $todo): array
    {
        $todo->loadMissing(['media:id,filename,mime', 'performancePlan:id,name,slug', 'performancePlans:id,name,slug,indikator_kinerja,satuan', 'event:id,todo_id']);
        $mediaItems = $this->mediaItems($todo);
        $planIds = $todo->performancePlans->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($planIds === [] && $todo->performance_plan_id) {
            $planIds = [(int) $todo->performance_plan_id];
        }

        return [
            'id' => (int) $todo->id,
            'event_id' => $todo->event_id ? (int) $todo->event_id : null,
            'media_id' => $todo->media_id ? (int) $todo->media_id : null,
            'media_ids' => $this->mediaIdsOf($todo),
            'performance_plan_id' => $todo->performance_plan_id ? (int) $todo->performance_plan_id : null,
            'performance_plan_ids' => $planIds,
            'performance_plans' => $todo->performancePlans->map(fn ($plan) => [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'indikator_kinerja' => $plan->indikator_kinerja,
                'satuan' => $plan->satuan,
            ])->values()->all(),
            'title' => (string) $todo->title,
            'done' => (bool) $todo->done,
            'due_at' => $todo->due_at ? $todo->due_at->toIso8601String() : null,
            'priority' => (string) ($todo->priority ?: 'sedang'),
            'notes' => $todo->notes,
            'durasi_jam' => $todo->durasi_jam !== null ? (float) $todo->durasi_jam : null,
            'tindak_lanjut' => $todo->tindak_lanjut,
            'media' => $mediaItems[0] ?? null,
            'media_items' => $mediaItems,
            'performance_plan' => $todo->performancePlan ? [
                'id' => (int) $todo->performancePlan->id,
                'name' => $todo->performancePlan->name,
                'slug' => $todo->performancePlan->slug,
            ] : null,
            'created_at' => $todo->created_at ? $todo->created_at->toIso8601String() : null,
        ];
    }

    private function rules(bool $creating): array
    {
        $media = Rule::exists('media', 'id')->where('user_id', auth()->id());
        $plan = Rule::exists('performance_plans', 'id')->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', auth()->id()));

        return [
            'title' => $creating ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'done' => 'sometimes|boolean',
            'due_at' => 'nullable|date',
            'priority' => 'nullable|in:tinggi,sedang,rendah',
            'notes' => 'nullable|string|max:2000',
            'durasi_jam' => 'nullable|numeric|min:0.25|max:24',
            'tindak_lanjut' => 'nullable|string|max:2000',
            'sync_calendar' => 'sometimes|boolean',
            'media_id' => ['nullable', 'integer', $media],
            'media_ids' => 'sometimes|array',
            'media_ids.*' => ['integer', $media],
            'performance_plan_id' => ['nullable', 'integer', $plan],
            'performance_plan_ids' => 'sometimes|array',
            'performance_plan_ids.*' => ['integer', $plan],
        ];
    }

    public function index()
    {
        $todos = Todo::where('user_id', auth()->id())
            ->with(['media:id,filename,mime', 'performancePlan:id,name,slug', 'performancePlans:id,name,slug,indikator_kinerja,satuan', 'event:id,todo_id'])
            ->orderBy('done')
            ->orderByRaw("case priority when 'tinggi' then 0 when 'sedang' then 1 else 2 end")
            ->orderByDesc('id')
            ->get()
            ->map(fn ($t) => $this->serialize($t));

        return response()->json(['todos' => $todos]);
    }

    public function show(string $id)
    {
        $todo = Todo::where('user_id', auth()->id())
            ->with(['media:id,filename,mime', 'performancePlan:id,name,slug', 'performancePlans:id,name,slug,indikator_kinerja,satuan', 'event:id,todo_id'])
            ->findOrFail($id);

        return response()->json(['todo' => $this->serialize($todo)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $sync = $request->boolean('sync_calendar');
        $planIds = $this->planIds($data);
        $mediaIds = $this->mediaIdsFromData($data);

        $todo = DB::transaction(function () use ($data, $sync, $planIds, $mediaIds) {
            $todo = TodoEventSync::createTodo([
                'user_id' => auth()->id(),
                'title' => $data['title'],
                'done' => false,
                'due_at' => isset($data['due_at']) ? $this->toAppTz($data['due_at']) : null,
                'priority' => $data['priority'] ?? 'sedang',
                'notes' => $data['notes'] ?? null,
                'durasi_jam' => $data['durasi_jam'] ?? null,
                'tindak_lanjut' => $data['tindak_lanjut'] ?? null,
                'media_id' => $mediaIds[0] ?? null,
                'media_ids' => $mediaIds ?: null,
                'performance_plan_id' => $planIds[0] ?? null,
            ], $sync);

            TodoEventSync::syncPlans($todo, $planIds);

            return $todo;
        });

        return response()->json(['todo' => $this->serialize($todo)], 201);
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

    private function mediaIdsOf(Todo $todo): array
    {
        $ids = is_array($todo->media_ids) ? $todo->media_ids : [];
        if ($ids === [] && $todo->media_id) {
            $ids = [(int) $todo->media_id];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function mediaItems(Todo $todo): array
    {
        $ids = $this->mediaIdsOf($todo);
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
        $todo = Todo::where('user_id', auth()->id())->findOrFail($id);
        $data = $request->validate($this->rules(false));
        $hasSync = $request->has('sync_calendar');
        $sync = $request->boolean('sync_calendar');

        DB::transaction(function () use ($todo, $data, $hasSync, $sync) {
            $nextDue = null;
            if (array_key_exists('due_at', $data)) {
                $nextDue = $data['due_at'] === null ? null : $this->toAppTz($data['due_at']);
            } elseif ($todo->due_at) {
                $nextDue = $todo->due_at;
            }

            if ($todo->event_id && (!$nextDue || ($hasSync && ! $sync))) {
                TodoEventSync::detachTodo($todo);
            }

            if (array_key_exists('title', $data)) {
                $todo->title = $data['title'];
            }
            if (array_key_exists('done', $data)) {
                $todo->done = $data['done'];
            }
            if (array_key_exists('due_at', $data)) {
                $todo->due_at = $nextDue;
            }
            if (array_key_exists('priority', $data)) {
                $todo->priority = $data['priority'] ?: 'sedang';
            }
            if (array_key_exists('notes', $data)) {
                $todo->notes = $data['notes'];
            }
            if (array_key_exists('durasi_jam', $data)) {
                $todo->durasi_jam = $data['durasi_jam'];
            }
            if (array_key_exists('tindak_lanjut', $data)) {
                $todo->tindak_lanjut = $data['tindak_lanjut'];
            }
            if (array_key_exists('media_ids', $data) || array_key_exists('media_id', $data)) {
                $mediaIds = $this->mediaIdsFromData($data);
                $todo->media_id = $mediaIds[0] ?? null;
                $todo->media_ids = $mediaIds ?: null;
            }
            $planIds = null;
            if (array_key_exists('performance_plan_ids', $data) || array_key_exists('performance_plan_id', $data)) {
                $planIds = $this->planIds($data);
                $todo->performance_plan_id = $planIds[0] ?? null;
            }

            $todo->save();

            if ($planIds !== null) {
                TodoEventSync::syncPlans($todo, $planIds);
            }

            if ($hasSync && $sync && ! $todo->event_id) {
                TodoEventSync::attachEvent($todo);
            }
        });

        return response()->json(['todo' => $this->serialize($todo)]);
    }

    public function complete(Request $request, string $id)
    {
        $todo = Todo::where('user_id', auth()->id())->findOrFail($id);
        $data = $request->validate([
            'tindak_lanjut' => 'nullable|string|max:2000',
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('user_id', auth()->id())],
            'media_ids' => 'sometimes|array',
            'media_ids.*' => ['integer', Rule::exists('media', 'id')->where('user_id', auth()->id())],
        ]);

        if (array_key_exists('tindak_lanjut', $data)) {
            $todo->tindak_lanjut = $data['tindak_lanjut'];
        }
        if (array_key_exists('media_ids', $data) || array_key_exists('media_id', $data)) {
            $mediaIds = $this->mediaIdsFromData($data);
            $todo->media_id = $mediaIds[0] ?? null;
            $todo->media_ids = $mediaIds ?: null;
        }
        $todo->done = true;
        $todo->save();

        return response()->json(['todo' => $this->serialize($todo)]);
    }

    // Client mengirim ISO ber-offset (mis. ...Z); cast datetime membaca nilai
    // tersimpan sebagai config app.timezone, jadi konversi ke WITA dulu.
    private function toAppTz(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    public function destroy(string $id)
    {
        DB::transaction(function () use ($id) {
            Todo::where('user_id', auth()->id())->findOrFail($id)->delete();
        });

        return response()->json(['ok' => true]);
    }
}
