<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Media;
use App\Models\PerformancePlan;
use App\Models\Todo;
use App\Models\User;
use App\Support\PerformanceReportBuilder;
use App\Support\TodoEventSync;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $user = Auth::user();
        $plans = PerformancePlan::where(function ($q) use ($user) {
            $q->whereNull('user_id')->orWhere('user_id', $user->id);
        })->orderBy('id')->get();

        return response()->json(['plans' => $plans]);
    }

    public function storePlan(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'name' => 'required|string|max:150',
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
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $slug,
            'penanggung_jawab_nama' => $user->name,
            'penanggung_jawab_jabatan' => $user->jabatan ?? $user->role,
        ]);

        return response()->json([
            'plan' => $plan,
        ], 201);
    }

    public function updatePlan(Request $request, PerformancePlan $plan)
    {
        $user = Auth::user();
        abort_if($plan->user_id && $plan->user_id !== $user->id, 403);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'atasan_intervensi' => 'nullable|string|max:1000',
            'rencana_kondisi' => 'nullable|string|max:1000',
            'indikator_kinerja' => 'nullable|string|max:1000',
            'satuan' => 'nullable|string|max:50',
            'target_tahunan' => 'nullable|string|max:50',
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
            'atasan_intervensi' => $data['atasan_intervensi'] ?? $plan->atasan_intervensi,
            'rencana_kondisi' => $data['rencana_kondisi'] ?? $plan->rencana_kondisi,
            'indikator_kinerja' => $data['indikator_kinerja'] ?? $plan->indikator_kinerja,
            'satuan' => $data['satuan'] ?? $plan->satuan,
            'target_tahunan' => $data['target_tahunan'] ?? $plan->target_tahunan,
            'penanggung_jawab_nama' => $user->name,
            'penanggung_jawab_jabatan' => $user->jabatan ?? $user->role,
        ]);

        return response()->json(['plan' => $plan->fresh()]);
    }

    public function destroyPlan(PerformancePlan $plan)
    {
        $user = Auth::user();
        if ($plan->user_id && $plan->user_id !== $user->id) {
            abort(403);
        }
        $plan->delete();

        return response()->json(['ok' => true]);
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->month($request);
        $snapshot = $this->snapshot(Auth::id(), $start, $end);

        return response()->json($snapshot['data']);
    }

    public function generateNote(Request $request)
    {
        $user = Auth::user();
        [$start, $end] = $this->month($request);
        $snapshot = $this->snapshot($user->id, $start, $end);
        $plans = collect($snapshot['data']['plans'])->map(fn (array $plan) => [
            'nama_indikator' => $plan['indikator_kinerja'] ?: $plan['name'],
            'label_rencana' => $plan['name'],
            'atasan_intervensi' => $plan['atasan_intervensi'],
            'sasaran_kondisi' => $plan['rencana_kondisi'],
            'satuan' => $plan['satuan'],
            'target_tahunan' => $plan['target_tahunan'],
            'jumlah_kegiatan' => $plan['activities'],
            'total_jam' => $plan['hours'],
            'kegiatan_selesai' => $plan['done'],
            'kegiatan_berbukti' => $plan['evidence'],
        ])->values()->all();

        $items = $snapshot['items']->map(fn ($item) => [
            'sumber' => $item['type'] === 'event' ? 'Kalender' : 'Todo',
            'tanggal' => $item['at']?->locale('id')->translatedFormat('d F Y, H:i'),
            'selesai_pada' => $item['end_at']?->locale('id')->translatedFormat('d F Y, H:i'),
            'kegiatan' => $item['title'],
            'catatan' => $item['notes'],
            'prioritas' => $item['priority'],
            'lokasi' => $item['location'],
            'pengulangan' => $item['recurrence'],
            'indikator' => $item['plan_name'],
            'jam' => $item['hours'],
            'status' => $item['done'] ? 'selesai' : 'aktif',
            'tindak_lanjut' => $item['tindak_lanjut'],
            'ada_bukti' => ! empty($item['evidence']),
        ])->values()->all();

        $prompt = "Buat catatan laporan kinerja yang rapi, ringkas, dan lengkap berdasarkan data berikut.\n".
            "Nama: ".($user->name ?? '-')."\nJabatan: ".($user->jabatan ?? $user->role ?? '-')."\nPeriode: ".$snapshot['data']['month_label']."\n\n".
            "Data seluruh indikator kinerja pengguna:\n".json_encode($plans, JSON_UNESCAPED_UNICODE)."\n\n".
            "Data seluruh kegiatan Todo dan Kalender pada periode ini yang terkait indikator, mencakup semua isian (catatan/deskripsi, prioritas, lokasi, jadwal, durasi, status, tindak lanjut, bukti):\n".json_encode($items, JSON_UNESCAPED_UNICODE)."\n\n".
            "Tugas:\n- Tulis 6-7 paragraf (14-18 kalimat), bukan daftar seluruh kegiatan\n- Paragraf 1: ringkasan capaian keseluruhan (kegiatan, jam, rata-rata jam, selesai, bukti)\n- Paragraf 2: analisis performa per indikator dengan menyebut indikator yang diisi pengguna, bukan label rencana\n- Paragraf 3: distribusi capaian dan indikator yang belum memiliki kegiatan\n- Paragraf 4: analisis tindak lanjut, konsistensi, dan kelengkapan bukti dukung\n- Paragraf 5: kendala/temuan berdasarkan data\n- Paragraf 6: rekomendasi perbaikan yang konkret\n- Paragraf 7: kesimpulan singkat\n- Gunakan seluruh data yang relevan, tetapi rangkum pola dan capaian; jangan merinci setiap kegiatan satu per satu\n- Jangan menyebutkan nama file lampiran; cukup nilai keberadaan atau jumlah bukti dukung\n\nGunakan bahasa Indonesia yang rapi dan analitis, tidak mengarang fakta di luar data.";

        $note = '';
        try {
            $base = config('services.hermes.base_url') ?: env('HERMES_BASE_URL');
            $key = config('services.hermes.key') ?: env('HERMES_API_KEY');
            if ($base && $key) {
                $res = Http::withHeaders(['Authorization' => 'Bearer '.$key])->timeout(90)->post(rtrim($base, '/').'/v1/chat/completions', [
                    'model' => config('services.hermes.model') ?: env('HERMES_MODEL') ?: 'hermes',
                    'messages' => [
                        ['role' => 'system', 'content' => 'Kamu analis kinerja yang kritis, faktual, berbasis seluruh isian kegiatan (Todo/Kalender) dan indikator.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 1400,
                    'temperature' => 0.25,
                ]);
                if ($res->ok()) {
                    $note = $res->json('choices.0.message.content') ?? $res->json('choices.0.text') ?? '';
                }
            }
        } catch (\Throwable $e) {
            $note = '';
        }

        if (trim($note) === '') {
            $done = collect($items)->where('status', 'selesai')->count();
            $total = count($items);
            $withTl = collect($items)->filter(fn ($i) => ! empty($i['tindak_lanjut']))->count();
            $withBukti = collect($items)->where('ada_bukti', true)->count();
            $tlNoBukti = collect($items)->filter(fn ($i) => ! empty($i['tindak_lanjut']) && empty($i['ada_bukti']))->count();
            $doneNoTl = collect($items)->filter(fn ($i) => $i['status'] === 'selesai' && empty($i['tindak_lanjut']))->count();
            $pct = $total > 0 ? round(($done / $total) * 100, 1) : 0;
            $pctTl = $total > 0 ? round(($withTl / $total) * 100, 1) : 0;
            $pctBukti = $total > 0 ? round(($withBukti / $total) * 100, 1) : 0;
            $totalHours = round(collect($items)->sum(fn ($i) => (float) ($i['jam'] ?? 0)), 2);
            $avgHours = $total > 0 ? round($totalHours / $total, 2) : 0;
            $activePlans = collect($plans)->filter(fn (array $plan) => $plan['jumlah_kegiatan'] > 0);
            $emptyPlans = collect($plans)->filter(fn (array $plan) => $plan['jumlah_kegiatan'] === 0)->pluck('nama_indikator');
            $topPlan = $activePlans->sortByDesc('total_jam')->first();
            $issues = array_values(array_filter([
                $pct < 100 ? 'belum seluruh kegiatan selesai' : null,
                $pctTl < 100 ? 'tindak lanjut belum terdokumentasi pada seluruh kegiatan' : null,
                $pctBukti < 100 ? 'bukti dukung belum lengkap pada sebagian kegiatan' : null,
            ]));
            $planText = $activePlans->map(fn (array $plan) => $plan['nama_indikator'].' ('.$plan['jumlah_kegiatan'].' kegiatan, '.number_format((float) $plan['total_jam'], 2, ',', '.').' jam, '.$plan['kegiatan_selesai'].' selesai)')->implode('; ');
            $note = "Ringkasan capaian periode ".$snapshot['data']['month_label'].": tercatat $total kegiatan dengan total ".number_format($totalHours, 2, ',', '.')." jam atau rata-rata ".number_format($avgHours, 2, ',', '.')." jam per kegiatan. Sebanyak $done kegiatan selesai ($pct%), $withBukti kegiatan memiliki bukti dukung ($pctBukti%), dan $withTl kegiatan memiliki tindak lanjut terdokumentasi ($pctTl%).\n\n".
                "Analisis performa menunjukkan indikator yang diisi pengguna sebagai berikut: ".($planText ?: 'belum ada indikator dengan kegiatan').'.'.($topPlan ? ' Indikator dengan kontribusi jam terbesar adalah '.$topPlan['nama_indikator'].' dengan '.number_format((float) $topPlan['total_jam'], 2, ',', '.').' jam.' : '')."\n\n".
                "Dari sisi distribusi, ".($emptyPlans->isNotEmpty() ? 'indikator yang belum memiliki kegiatan adalah '.$emptyPlans->implode(', ').'.' : 'seluruh indikator sudah memiliki kegiatan pada periode ini.')." Pemerataan kegiatan dan realisasi perlu diperhatikan agar seluruh indikator terpantau, bukan hanya indikator dengan aktivitas terbanyak.\n\n".
                "Analisis tindak lanjut menunjukkan $withTl dari $total kegiatan sudah memiliki catatan tindak lanjut ($pctTl%), sedangkan bukti dukung tersedia pada $withBukti kegiatan ($pctBukti%).".($tlNoBukti > 0 ? ' Terdapat '.$tlNoBukti.' kegiatan yang memiliki tindak lanjut tetapi belum dilengkapi bukti dukung.' : '').($doneNoTl > 0 ? ' Sebanyak '.$doneNoTl.' kegiatan selesai belum memiliki tindak lanjut yang terdokumentasi.' : '')." Konsistensi pendokumentasian masih perlu dijaga agar capaian mudah ditelusuri.\n\n".
                "Kendala utama yang terlihat dari data: ".($issues ? implode(', ', $issues) : 'tidak ditemukan kendala berarti pada kelengkapan data').".\n\n".
                "Rekomendasi: selesaikan kegiatan yang masih aktif, lengkapi tindak lanjut dan bukti dukung pada kegiatan yang belum terdokumentasi, serta pastikan setiap indikator memiliki kegiatan dan target realisasi yang terukur. Untuk indikator tanpa kegiatan, perlu ditetapkan rencana tindak lanjut agar capaian periode berikutnya lebih merata.\n\n".
                "Kesimpulan: pelaksanaan kegiatan berjalan sesuai indikator dengan tingkat penyelesaian $pct%; perbaikan utama ada pada kelengkapan dokumentasi tindak lanjut dan bukti dukung.";
        }

        return response()->json(['note' => trim($note)]);
    }

    public function store(Request $request)
    {
        [$start, $end] = $this->month($request);
        $user = Auth::user();
        $snapshot = $this->snapshot($user->id, $start, $end);
        $data = $snapshot['data'];
        $catatan = $request->input('catatan');

        $matrix = [[
            ['text' => 'No.', 'bold' => true],
            ['text' => 'Atasan Langsung / Rencana Intervensi', 'bold' => true],
            ['text' => 'Sasaran Strategis / Kondisi yang Diwujudkan', 'bold' => true],
            ['text' => 'Indikator Kinerja (Ukuran Keberhasilan) & Satuan', 'bold' => true],
            ['text' => 'Target & Realisasi', 'bold' => true],
            ['text' => 'Rincian Kegiatan, Durasi Jam Kerja, & Tindak Lanjut Terkait', 'bold' => true],
            ['text' => 'Penanggung Jawab', 'bold' => true],
        ]];

        foreach ($data['plans'] as $i => $plan) {
            $pj = collect([$plan['penanggung_jawab_nama'] ?? $user->name, $plan['penanggung_jawab_jabatan'] ?? ($user->jabatan ?? $user->role)])->filter()->implode(' - ');
            $indikator = trim(($plan['indikator_kinerja'] ?: $plan['name']).($plan['satuan'] ? ' / '.$plan['satuan'] : ''));
            $targetRealisasi = 'Target: '.($plan['target_tahunan'] ?: '-').' | Realisasi: '.($plan['done'] ?? 0).' kegiatan / '.number_format($plan['hours'] ?? 0, 2, ',', '.').' jam';
            $rincian = '';
            if (! empty($plan['activities_detail'])) {
                foreach ($plan['activities_detail'] as $ad) {
                    $rincian .= '- '.$ad['title'].' ('.$ad['at'].', '.$ad['hours'].' jam, '.($ad['done'] ? 'Selesai' : 'Aktif').')'.($ad['tindak_lanjut'] ? ' TL: '.$ad['tindak_lanjut'] : '')."\n";
                }
            }
            if ($rincian === '') {
                $rincian = '-';
            }

            $matrix[] = [
                (string) ($i + 1),
                $plan['atasan_intervensi'] ?: '-',
                $plan['rencana_kondisi'] ?: '-',
                $indikator ?: '-',
                $targetRealisasi,
                $rincian,
                $pj ?: '-',
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
                $item['at']->locale('id')->translatedFormat('d F Y, H:i'),
                $item['title'],
                $item['plan_name'],
                $item['hours'] !== null ? $this->hours($item['hours']) : '-',
                $item['done'] ? 'Selesai' : 'Aktif',
                $item['tindak_lanjut'] ?: '-',
                $item['evidence'] ?: '-',
            ];
        }

        $noteText = $catatan ? trim($catatan) : 'Sumber data: Todo, Tindak Lanjut, dan Bukti Dukung';
        $base = $request->input('base_url');
        $base = is_string($base) && preg_match('#^https?://#i', $base) ? rtrim($base, '/') : $request->getSchemeAndHttpHost();
        $token = $user->api_token;
        // shortcut: tautan memakai api_token user (sama seperti URL media aplikasi); ganti signed URL berumur pendek bila PDF dibagikan.
        $attachmentGroups = $snapshot['attachment_groups']->map(fn (array $group) => [
            'title' => $group['title'],
            'meta' => $group['at'].' | '.$group['plan_name'],
            'attachments' => array_map(function (array $item) use ($base, $token) {
                $full = storage_path('app/private/'.$item['path']);
                $isImage = str_starts_with($item['mime'], 'image/');
                // shortcut: gambar >8 MB hanya ditautkan (bukan disematkan) agar PDF tidak membengkak; turunkan ukuran via GD bila perlu.
                $embed = $isImage && is_file($full) && filesize($full) <= 8 * 1024 * 1024;

                return [
                    'filename' => $item['filename'],
                    'image' => $embed ? 'data:'.$item['mime'].';base64,'.base64_encode((string) file_get_contents($full)) : null,
                    'url' => $base.'/api/media/'.$item['id'].($token ? '?auth='.urlencode($token) : ''),
                ];
            }, $group['attachments']),
        ])->values()->all();

        $media = PerformanceReportBuilder::save([
            'filename' => 'Laporan-Kinerja-'.$start->format('Y-m').'.pdf',
            'title' => 'LAPORAN KINERJA BULANAN',
            'subtitle' => 'Periode '.$data['month_label'],
            'summary' => [
                ['Total kegiatan', (string) $data['totals']['activities']],
                ['Total jam', $this->hours($data['totals']['hours']).' jam'],
                ['Bukti fisik', (string) $data['totals']['evidence']],
                ['Kegiatan selesai', (string) $data['totals']['done']],
                ['Penanggung Jawab', ($user->name ?: '-').($user->jabatan ? ' - '.$user->jabatan : '')],
            ],
            'matrix' => $matrix,
            'details' => $details,
            'attachment_groups' => $attachmentGroups,
            'note' => $noteText,
            'signature' => [
                'name' => $user->name ?: '-',
                'jabatan' => $user->jabatan ?: ($user->role ?: '-'),
                'date' => Carbon::now('Asia/Makassar')->locale('id')->translatedFormat('l, d F Y'),
            ],
        ], $user->id);

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

        $todo = DB::transaction(function () use ($template, $start, $plan) {
            $todo = TodoEventSync::createTodo([
                'user_id' => Auth::id(),
                'title' => $template['title'],
                'done' => false,
                'due_at' => $start->copy()->setTime(9, 0),
                'priority' => 'sedang',
                'notes' => 'Dibuat dari template kinerja.',
                'durasi_jam' => $template['duration'],
                'performance_plan_id' => $plan->id,
            ], true);

            TodoEventSync::syncPlans($todo, [(int) $plan->id]);

            return $todo;
        });

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
        $month = $data['month'] ?? now('Asia/Makassar')->format('Y-m');
        $start = Carbon::parse($month.'-01', 'Asia/Makassar')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function snapshot(int $userId, Carbon $start, Carbon $end): array
    {
        $user = User::find($userId);
        // Indikator global tetap tampil bagi pengguna yang pernah memakainya, agar tidak hilang saat ganti bulan.
        $plans = PerformancePlan::where(function ($q) use ($userId) {
            $q->where('user_id', $userId)
                ->orWhere(function ($q) use ($userId) {
                    $q->whereNull('user_id')
                        ->where(function ($q) use ($userId) {
                            $q->whereHas('todos', fn ($q) => $q->where('user_id', $userId))
                                ->orWhereHas('events', fn ($q) => $q->where('user_id', $userId)->whereNull('todo_id'));
                        });
                });
        })->orderBy('id')->get();

        $todos = Todo::where('user_id', $userId)
            ->whereBetween('due_at', [$start, $end])
            ->where(function ($q) {
                $q->whereNotNull('performance_plan_id')->orWhereHas('performancePlans');
            })
            ->with(['media:id,filename,mime', 'performancePlans:id,name,indikator_kinerja'])
            ->get();

        $events = Event::where('user_id', $userId)
            ->whereNull('todo_id')
            ->whereBetween('start_at', [$start, $end])
            ->where(function ($q) {
                $q->whereNotNull('performance_plan_id')->orWhereHas('performancePlans');
            })
            ->with(['media:id,filename,mime', 'performancePlans:id,name,indikator_kinerja'])
            ->get();

        $allowedPlanIds = $plans->pluck('id')->map(fn ($id) => (int) $id)->all();
        $itemPlans = function (Todo|Event $model) use ($plans, $allowedPlanIds): array {
            $related = $model->performancePlans->filter(fn ($plan) => in_array((int) $plan->id, $allowedPlanIds, true));
            $ids = $related->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $names = $related->map(fn ($plan) => $plan->indikator_kinerja ?: $plan->name)->values()->all();
            if ($ids === [] && $model->performance_plan_id) {
                $legacyId = (int) $model->performance_plan_id;
                $plan = $plans->firstWhere('id', $legacyId);
                if ($plan) {
                    $ids = [$legacyId];
                    $names = [$plan->indikator_kinerja ?: $plan->name];
                }
            }

            return [$ids, $names === [] ? '-' : implode(', ', $names)];
        };

        $items = $todos->map(function (Todo $todo) use ($itemPlans) {
            [$ids, $names] = $itemPlans($todo);

            return [
                'at' => $todo->due_at,
                'title' => $todo->title,
                'plan_ids' => $ids,
                'plan_name' => $names,
                'hours' => $todo->durasi_jam !== null ? (float) $todo->durasi_jam : null,
                'done' => (bool) $todo->done,
                'tindak_lanjut' => $todo->tindak_lanjut,
                'type' => 'todo',
                'notes' => $todo->notes,
                'priority' => $todo->priority,
                'location' => null,
                'end_at' => null,
                'recurrence' => null,
                'evidence_ids' => $this->mediaIdsOf($todo),
            ];
        })->concat($events->map(function (Event $event) use ($itemPlans) {
            [$ids, $names] = $itemPlans($event);

            return [
                'at' => $event->start_at,
                'title' => $event->title,
                'plan_ids' => $ids,
                'plan_name' => $names,
                'hours' => $event->durasi_jam !== null ? (float) $event->durasi_jam : null,
                'done' => false,
                'tindak_lanjut' => $event->tindak_lanjut,
                'type' => 'event',
                'notes' => $event->description,
                'priority' => null,
                'location' => $event->location,
                'end_at' => $event->end_at,
                'recurrence' => $event->recurrence,
                'evidence_ids' => $this->mediaIdsOf($event),
            ];
        }))->sortBy(fn (array $item) => $item['at']->getTimestamp())->values();

        $mediaIds = $items->flatMap(fn (array $item) => $item['evidence_ids'])->unique()->values();
        $mediaById = $mediaIds->isEmpty() ? collect() : Media::whereIn('id', $mediaIds)->get()->keyBy('id');
        $filesOf = fn (array $item) => array_values(array_filter(array_map(fn ($id) => $mediaById->get((int) $id), $item['evidence_ids'])));

        $items = $items->map(function (array $item) use ($filesOf) {
            $item['evidence_list'] = array_map(fn (Media $media) => $media->filename, $filesOf($item));
            $item['evidence'] = implode(', ', $item['evidence_list']) ?: null;

            return $item;
        });

        $attachmentGroups = $items->map(fn (array $item) => [
            'title' => $item['title'],
            'at' => $item['at']?->locale('id')->translatedFormat('d F Y, H:i') ?? '-',
            'plan_name' => $item['plan_name'],
            'attachments' => array_map(fn (Media $media) => [
                'id' => (int) $media->id,
                'filename' => $media->filename,
                'mime' => $media->mime,
                'path' => $media->path,
            ], $filesOf($item)),
        ])->filter(fn (array $group) => $group['attachments'] !== [])->values();

        $rows = $plans->map(function (PerformancePlan $plan) use ($items, $user) {
            $group = $items->filter(fn ($item) => in_array((int) $plan->id, $item['plan_ids'], true))->values();
            $activitiesDetail = $group->map(fn ($item) => [
                'title' => $item['title'],
                'at' => $item['at']?->locale('id')->translatedFormat('d F Y, H:i') ?? '-',
                'hours' => number_format((float) ($item['hours'] ?? 0), 2, ',', '.'),
                'done' => $item['done'],
                'tindak_lanjut' => $item['tindak_lanjut'],
            ])->values()->all();

            return [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'user_id' => $plan->user_id,
                'atasan_intervensi' => $plan->atasan_intervensi,
                'rencana_kondisi' => $plan->rencana_kondisi,
                'indikator_kinerja' => $plan->indikator_kinerja,
                'satuan' => $plan->satuan,
                'target_tahunan' => $plan->target_tahunan,
                'penanggung_jawab_nama' => $user?->name,
                'penanggung_jawab_jabatan' => $user->jabatan ?? $user->role,
                'activities' => $group->count(),
                'hours' => round($group->sum(fn ($item) => (float) ($item['hours'] ?? 0)), 2),
                'evidence' => $group->filter(fn ($item) => $item['evidence_list'] !== [])->count(),
                'evidence_files' => $group->pluck('evidence_list')->flatten()->unique()->values()->all(),
                'done' => $group->where('done', true)->count(),
                'activities_detail' => $activitiesDetail,
            ];
        })->all();

        $totals = [
            'activities' => $items->count(),
            'hours' => round($items->sum(fn ($item) => (float) ($item['hours'] ?? 0)), 2),
            'evidence' => $items->filter(fn ($item) => $item['evidence_list'] !== [])->count(),
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
                'timezone' => 'Asia/Makassar',
            ],
            'items' => $items,
            'attachment_groups' => $attachmentGroups,
        ];
    }

    private function hours(float $hours): string
    {
        return number_format($hours, 2, ',', '.');
    }

    private function mediaIdsOf(Todo|Event $model): array
    {
        $ids = is_array($model->media_ids) ? $model->media_ids : [];
        if ($ids === [] && $model->media_id) {
            $ids = [(int) $model->media_id];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
