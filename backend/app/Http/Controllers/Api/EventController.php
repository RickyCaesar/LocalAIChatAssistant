<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

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
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'] ?? null,
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

        $event->fill($data)->save();

        return response()->json(['event' => $this->serialize($event)]);
    }

    public function destroy(string $id)
    {
        Event::where('user_id', auth()->id())->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}