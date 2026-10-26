<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\PerformancePlan;
use App\Models\Todo;
use App\Support\PerformanceReportBuilder;
use App\Support\TodoEventSync;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerformanceReportController extends Controller
{
    private const TEMPLATES = [
        'penginputan-dssd-bulanan' => [
            'title' => 'Penginputan DSSD Bulanan',
            'plan' => 'dssd',
            'duration' => 2,
        ],
        'penyusunan-laporan-realisasi' => [
            'title' => 'Penyusunan Laporan Realisasi Fisik & Keuangan',
            'plan' => 'lapkin',
            'duration' => 4,
        ],
    ];

    public function plans()
    {
        return response()->json([
            'plans' => PerformancePlan::orderBy('id')->get(['id', 'name', 'slug']),
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($data['name']);
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'indikator-'.Str::lower(Str::random(6));
        }

        $base = $slug;
        $i = 1;
        while (PerformancePlan::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        $plan = PerformancePlan::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'plan' => [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ],
        ], 201);
    }

    public function updatePlan(Request $request, PerformancePlan $plan)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($data['name']);
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = $plan->slug;
        }

        if (PerformancePlan::where('slug', $slug)->where('id', '!=', $plan->id)->exists()) {
            $base = $slug;
            $i = 1;
            do {
                $slug = $base.'-'.$i++;
            } while (PerformancePlan::where('slug', $slug)->where('id', '!=', $plan->id)->exists());
        }

        $plan->update([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'plan' => [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ],
        ]);
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->month($request);
        $snapshot = $this->snapshot(auth()->id(), $start, $end);

        return response()->json($snapshot['data']);
    }

    public function store(Request $request)
    {
        [$start, $end] = $this->month($request);
        $snapshot = $this->snapshot(auth()->id(), $start, $end);
        $data = $snapshot['data'];

        $matrix = [[
            ['text' => 'Indikator', 'bold' => true],
            ['text' => 'Kegiatan', 'bold' => true],
            ['text' => 'Jam', 'bold' => true],
            ['text' => 'Bukti', 'bold' => true],
            ['text' => 'Selesai', 'bold' => true],
        ]];
        foreach ($data['plans'] as $plan) {
            $matrix[] = [
                $plan['name'],
                (string) $plan['activities'],
                $this->hours($plan['hours']),
                (string) $plan['evidence'],
                (string) $plan['done'],
            ];
        }

        $details = [[
            ['text' => 'No', 'bold' => true],
            ['text' => 'Tanggal', 'bold' => true],
            ['text' => 'Kegiatan', 'bold' => true],
            ['text' => 'Indikator', 'bold' => true],
            ['text' => 'Jam', 'bold' => true],
            ['text' => 'Status', 'bold' => true],
            ['text' => 'Tindak Lanjut', 'bold' => true],
            ['text' => 'Bukti', 'bold' => true],
        ]];

        foreach ($snapshot['items'] as $i => $item) {
            $details[] = [
                (string) ($i + 1),
                $item['at']->locale('id')->translatedFormat('d/m/Y H:i'),
                $item['title'],
                $item['plan_name'],
                $item['hours'] !== null ? $this->hours($item['hours']) : '-',
                $item['done'] ? 'Selesai' : 'Aktif',
                $item['tindak_lanjut'] ?: '-',
                $item['evidence'] ?: '-',
            ];
        }

        $media = PerformanceReportBuilder::save([
            'filename' => 'Laporan-Kinerja-'.$start->format('Y-m').'.docx',
            'title' => 'LAPORAN KINERJA BULANAN',
            'subtitle' => 'Integrasi e-SAKIP - '.$data['month_label'],
            'generated_at' => Carbon::now()->locale('id')->translatedFormat('d F Y H:i'),
            'summary' => [
                ['Total kegiatan', (string) $data['totals']['activities']],
                ['Total jam', $this->hours($data['totals']['hours']).' jam'],
                ['Bukti fisik', (string) $data['totals']['evidence']],
                ['Kegiatan selesai', (string) $data['totals']['done']],
            ],
            'matrix' => $matrix,
            'details' => $details,
            'note' => 'Sumber data: Todo dan Kalender e-SAKIP. Lampiran bukti mengikuti media yang ditautkan.',
        ], auth()->id());

        return response()->json([
            'media' => [
                'id' => (int) $media->id,
                'filename' => $media->filename,
                'mime' => $media->mime,
                'url' => '/api/media/'.$media->id,
            ],
        ], 201);
    }

    public function generateTemplate(Request $request, string $key)
    {
        $template = self::TEMPLATES[$key] ?? null;
        abort_if(! $template, 404, 'Template tidak ditemukan.');

        [$start] = $this->month($request);
        $plan = PerformancePlan::where('slug', $template['plan'])->firstOrFail();

        $todo = DB::transaction(fn () => TodoEventSync::createTodo([
            'user_id' => auth()->id(),
            'title' => $template['title'],
            'done' => false,
            'due_at' => $start->copy()->setTime(9, 0),
            'priority' => 'sedang',
            'notes' => 'Dibuat dari template e-SAKIP.',
            'durasi_jam' => $template['duration'],
            'performance_plan_id' => $plan->id,
        ], true));

        return response()->json([
            'todo' => [
                'id' => (int) $todo->id,
                'title' => $todo->title,
                'event_id' => $todo->event_id ? (int) $todo->event_id : null,
                'due_at' => $todo->due_at->toIso8601String(),
            ],
        ], 201);
    }

    private function month(Request $request): array
    {
        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::parse($month.'-01', config('app.timezone'))->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function snapshot(int $userId, Carbon $start, Carbon $end): array
    {
        $plans = PerformancePlan::orderBy('id')->get();
        $todos = Todo::where('user_id', $userId)
            ->whereNotNull('performance_plan_id')
            ->whereBetween('due_at', [$start, $end])
            ->with(['media:id,filename,mime', 'performancePlan:id,name,slug'])
            ->get();

        $events = Event::where('user_id', $userId)
            ->whereNotNull('performance_plan_id')
            ->whereNull('todo_id')
            ->whereBetween('start_at', [$start, $end])
            ->with(['media:id,filename,mime', 'performancePlan:id,name,slug'])
            ->get();

        $items = $todos->map(fn (Todo $todo) => [
            'at' => $todo->due_at,
            'title' => $todo->title,
            'plan_id' => (int) $todo->performance_plan_id,
            'plan_name' => $todo->performancePlan?->name ?? '-',
            'hours' => $todo->durasi_jam !== null ? (float) $todo->durasi_jam : null,
            'done' => (bool) $todo->done,
            'tindak_lanjut' => $todo->tindak_lanjut,
            'evidence' => $todo->media?->filename,
        ])->concat($events->map(fn (Event $event) => [
            'at' => $event->start_at,
            'title' => $event->title,
            'plan_id' => (int) $event->performance_plan_id,
            'plan_name' => $event->performancePlan?->name ?? '-',
            'hours' => $event->durasi_jam !== null ? (float) $event->durasi_jam : null,
            'done' => false,
            'tindak_lanjut' => $event->tindak_lanjut,
            'evidence' => $event->media?->filename,
        ]))->sortBy(fn (array $item) => $item['at']->getTimestamp())->values();

        $groups = $items->groupBy('plan_id');
        $rows = $plans->map(function (PerformancePlan $plan) use ($groups) {
            $group = $groups->get($plan->id, collect());

            return [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'activities' => $group->count(),
                'hours' => round($group->sum(fn ($item) => (float) ($item['hours'] ?? 0)), 2),
                'evidence' => $group->filter(fn ($item) => $item['evidence'] !== null)->count(),
                'evidence_files' => $group->pluck('evidence')->filter()->unique()->values()->all(),
                'done' => $group->where('done', true)->count(),
            ];
        })->all();

        $totals = [
            'activities' => $items->count(),
            'hours' => round($items->sum(fn ($item) => (float) ($item['hours'] ?? 0)), 2),
            'evidence' => $items->filter(fn ($item) => $item['evidence'] !== null)->count(),
            'done' => $items->where('done', true)->count(),
        ];

        $planIds = $plans->pluck('id', 'slug');
        $templates = collect(self::TEMPLATES)->map(function (array $template, string $key) use ($planIds) {
            return [
                'key' => $key,
                'title' => $template['title'],
                'plan_id' => $planIds[$template['plan']] ?? null,
                'duration_jam' => $template['duration'],
            ];
        })->values()->all();

        return [
            'data' => [
                'month' => $start->format('Y-m'),
                'month_label' => $start->locale('id')->translatedFormat('F Y'),
                'plans' => $rows,
                'totals' => $totals,
                'templates' => $templates,
            ],
            'items' => $items,
        ];
    }

    private function hours(float $hours): string
    {
        return number_format($hours, 2, ',', '.');
    }
}
