<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $todo->loadMissing(['media:id,filename,mime', 'performancePlan:id,name,slug', 'event:id,todo_id']);

        return [
            'id' => (int) $todo->id,
            'event_id' => $todo->event_id ? (int) $todo->event_id : null,
            'media_id' => $todo->media_id ? (int) $todo->media_id : null,
            'performance_plan_id' => $todo->performance_plan_id ? (int) $todo->performance_plan_id : null,
            'title' => (string) $todo->title,
            'done' => (bool) $todo->done,
            'due_at' => $todo->due_at ? $todo->due_at->toIso8601String() : null,
            'priority' => (string) ($todo->priority ?: 'sedang'),
            'notes' => $todo->notes,
            'durasi_jam' => $todo->durasi_jam !== null ? (float) $todo->durasi_jam : null,
            'tindak_lanjut' => $todo->tindak_lanjut,
            'media' => $todo->media ? [
                'id' => (int) $todo->media->id,
                'filename' => $todo->media->filename,
                'mime' => $todo->media->mime,
            ] : null,
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
            'performance_plan_id' => ['nullable', 'integer', Rule::exists('performance_plans', 'id')],
        ];
    }

    public function index()
    {
        $todos = Todo::where('user_id', auth()->id())
            ->with(['media:id,filename,mime', 'performancePlan:id,name,slug', 'event:id,todo_id'])
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
            ->with(['media:id,filename,mime', 'performancePlan:id,name,slug', 'event:id,todo_id'])
            ->findOrFail($id);

        return response()->json(['todo' => $this->serialize($todo)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $sync = $request->boolean('sync_calendar');

        $todo = DB::transaction(fn () => TodoEventSync::createTodo([
            'user_id' => auth()->id(),
            'title' => $data['title'],
            'done' => false,
            'due_at' => isset($data['due_at']) ? $this->toAppTz($data['due_at']) : null,
            'priority' => $data['priority'] ?? 'sedang',
            'notes' => $data['notes'] ?? null,
            'durasi_jam' => $data['durasi_jam'] ?? null,
            'tindak_lanjut' => $data['tindak_lanjut'] ?? null,
            'media_id' => $data['media_id'] ?? null,
            'performance_plan_id' => $data['performance_plan_id'] ?? null,
        ], $sync));

        return response()->json(['todo' => $this->serialize($todo)], 201);
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
            if (array_key_exists('media_id', $data)) {
                $todo->media_id = $data['media_id'];
            }
            if (array_key_exists('performance_plan_id', $data)) {
                $todo->performance_plan_id = $data['performance_plan_id'];
            }

            $todo->save();

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
        ]);

        if (array_key_exists('tindak_lanjut', $data)) {
            $todo->tindak_lanjut = $data['tindak_lanjut'];
        }
        if (array_key_exists('media_id', $data)) {
            $todo->media_id = $data['media_id'];
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
