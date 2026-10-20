<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventController extends Controller
{
    private function serialize(Event $event): array
    {
        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'start_at' => $event->start_at ? $event->start_at->toIso8601String() : null,
            'end_at' => $event->end_at ? $event->end_at->toIso8601String() : null,
            'description' => $event->description,
            'location' => $event->location,
            'recurrence' => (string) ($event->recurrence ?: 'none'),
        ];
    }

    public function index()
    {
        $events = Event::where('user_id', auth()->id())
            ->orderBy('start_at')
            ->get()
            ->map(fn ($e) => $this->serialize($e));

        return response()->json(['events' => $events]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'description' => 'nullable|string|max:2000',
            'location' => 'nullable|string|max:255',
            'recurrence' => 'nullable|in:none,weekly,monthly,yearly',
        ]);

        $event = Event::create([
            'user_id' => auth()->id(),
            'title' => $data['title'],
            'start_at' => $this->toAppTz($data['start_at']),
            'end_at' => isset($data['end_at']) ? $this->toAppTz($data['end_at']) : null,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'recurrence' => $data['recurrence'] ?? 'none',
        ]);

        return response()->json(['event' => $this->serialize($event)], 201);
    }

    public function update(Request $request, string $id)
    {
        $event = Event::where('user_id', auth()->id())->findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'start_at' => 'sometimes|required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'description' => 'nullable|string|max:2000',
            'location' => 'nullable|string|max:255',
            'recurrence' => 'nullable|in:none,weekly,monthly,yearly',
        ]);

        if (array_key_exists('start_at', $data)) {
            $data['start_at'] = $this->toAppTz($data['start_at']);
        }
        if (array_key_exists('end_at', $data)) {
            $data['end_at'] = $data['end_at'] === null ? null : $this->toAppTz($data['end_at']);
        }

        $event->fill($data)->save();

        return response()->json(['event' => $this->serialize($event)]);
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
        Event::where('user_id', auth()->id())->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}