<?php

namespace App\Support;

use App\Models\Event;
use App\Models\PerformancePlan;
use App\Models\Todo;
use App\Support\MarpGenerator;
use App\Support\NotulenBuilder;
use Symfony\Component\Process\Exception\ProcessFailedException;

// Menjalankan ACTION: <...> yang dikeluarkan model Hermes di dalam balasannya.
// Baris ACTION disaring dari teks yang tampak ke user dan diganti konfirmasi.
class ActionRunner
{
    public static function run(string $text, ?int $userId, ?int $sessionId = null): array
    {
        $results = [];
        if (! $userId) {
            return [self::stripActions($text), $results];
        }

        $lines = preg_split('/\R/', $text) ?: [];
        $keep = [];
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*ACTION:\s*([A-Z_]+)\s*(.*?)\s*$/i', $line, $m)) {
                $cmd = strtoupper($m[1]);
                $arg = trim($m[2] ?? '');
                if ($cmd === 'GENERATE_PPTX' && $arg === '') {
                    [$arg, $i] = self::pptxBlock($lines, $i);
                }
                $r = match ($cmd) {
                    'TODO_ADD' => self::todoAdd($userId, $arg),
                    'TODO_DONE' => self::todoDone($userId, $arg),
                    'TODO_DEL' => self::todoDel($userId, $arg),
                    'EVENT_ADD' => self::eventAdd($userId, $arg),
                    'EVENT_DEL' => self::eventDel($userId, $arg),
                    'DOC_BUILD' => self::docBuild($userId, $arg, $sessionId),
                    'NOTULEN_BUILD' => self::notulenBuild($userId, $arg, $sessionId),
                    'GENERATE_PPTX' => self::generatePptx($userId, $arg, $sessionId),
                    default => '✖ Perintah tidak dikenal: '.$cmd,
                };
                if ($r !== '') {
                    $results[] = $r;
                }
            } else {
                $keep[] = $line;
            }
        }

        return [trim(implode("\n", $keep), "\n"), $results];
    }

    // Snapshot data user saat ini, disisipkan ke konteks model agar Hermes
    // bisa menjawab "apa todo-ku?" dan "jadwalkan ...".
    public static function stateSnapshot(?int $userId): string
    {
        if (! $userId) {
            return '';
        }

        $todos = Todo::where('user_id', $userId)->where('done', false)->orderBy('id')->limit(200)->get();
        $events = Event::where('user_id', $userId)->where('start_at', '>=', now())->orderBy('start_at')->limit(200)->get();

        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $now = now();
        $besok = $now->copy()->addDay();
        $s = sprintf(
            "WAKTU SEKARANG (WITA/Asia/Makassar, UTC+8): %s %s. Hari ini %s (%s); besok %s (%s). Hitung semua tanggal relatif (\"besok\", \"lusa\", \"minggu depan\") dari sini.\n",
            $now->toDateString(),
            $now->format('H:i'),
            $now->toDateString(),
            $hari[(int) $now->dayOfWeek],
            $besok->toDateString(),
            $hari[(int) $besok->dayOfWeek]
        );
        $plans = PerformancePlan::where(function ($q) use ($userId) {
            $q->whereNull('user_id')->orWhere('user_id', $userId);
        })->orderBy('id')->get(['id', 'name', 'indikator_kinerja', 'satuan']);

        $s .= "CURRENT USER STATE — todo list & upcoming events (private data; use it to answer, but do not dump the entire list unless the user asks).\n";
        $s .= 'Todos (open only): '.($todos->isEmpty() ? 'none' : $todos->map(fn ($t) => sprintf('#%d "%s"', $t->id, $t->title))->join('; '))."\n";
        $s .= 'Events (upcoming): '.($events->isEmpty() ? 'none' : $events->map(fn ($e) => sprintf('%s %s "%s" (#%d)', $e->start_at->toDateString(), $e->start_at->format('H:i'), $e->title, $e->id))->join('; '))."\n";
        $s .= 'Indikator Kinerja tersedia (pakai id untuk performance_plan_ids): '.($plans->isEmpty() ? 'none' : $plans->map(fn ($p) => sprintf('#%d %s', $p->id, $p->indikator_kinerja ?: $p->name))->join('; '));

        return $s;
    }

    public static function toolsInstruction(): string
    {
        return "\n\nYou can also manage the user's Todo list and Calendar by emitting exact ACTION lines (one per operation). Never print ACTION lines as normal text — emit them, then tell the user what you did:\n"
            ."ACTION: TODO_ADD <single-line JSON> — isi field seperti form Todo: {\"title\":\"...\",\"due_at\":\"YYYY-MM-DD HH:MM\",\"priority\":\"tinggi|sedang|rendah\",\"notes\":\"...\",\"durasi_jam\":1.5,\"tindak_lanjut\":\"...\",\"performance_plan_ids\":[1,2],\"sync_calendar\":true}. Plain title is still accepted, but prefer JSON with all fields the user implies; leave unknown fields out. Use only Indikator IDs from CURRENT USER STATE.\n"
            ."ACTION: TODO_DONE <todo title or #id>\n"
            ."ACTION: TODO_DEL <todo title or #id>\n"
            ."ACTION: EVENT_ADD <single-line JSON> — isi field seperti form Kalender: {\"title\":\"...\",\"start_at\":\"YYYY-MM-DD HH:MM\",\"end_at\":\"YYYY-MM-DD HH:MM\",\"description\":\"...\",\"tindak_lanjut\":\"...\",\"performance_plan_ids\":[1],\"sync_todo\":false}. Old format <YYYY-MM-DD HH:MM> | <title> still accepted.\n"
            ."ACTION: EVENT_DEL <#id>\n"
            ."ACTION: DOC_BUILD <single-line JSON> — build an XLSX spreadsheet and send it to the chat/Gallery. Schema: {\"filename\":\"name.xlsx\",\"sheet\":\"Sheet\",\"title\":\"Report title\",\"blocks\":[{\"heading\":\"Section\",\"columns\":[\"Col A\",\"Col B\"],\"rows\":[[\"a1\",\"b1\"],[\"a2\",\"b2\"]]}]}. Use multiple blocks for several titled tables/lists.\n"
            ."ACTION: NOTULEN_BUILD <single-line JSON> — build a DOCX notulen rapat (pola Dinas Pendidikan Kaltim: kop, judul, identitas, ringkasan, agenda, pokok pembahasan, tanya-jawab, keputusan, action items, penutup) and send it to the chat/Gallery. Schema: {\"filename\":\"Notulen.docx\",\"judul\":\"NOTULENSI RAPAT\",\"subjudul\":\"Rapat Koordinasi BOSP\",\"identitas\":{\"Hari/Tanggal\":\"Selasa, 20 Oktober 2026\",\"Waktu\":\"09.00 WITA\",\"Tempat\":\"Daring via Zoom\",\"Moderator\":\"...\",\"Peserta\":\"...\"},\"ringkasan\":[\"...\"],\"agenda\":[\"...\"],\"pembahasan\":[{\"judul\":\"A. ...\",\"paragraf\":[\"...\"],\"poin\":[\"...\"]}],\"tanya_jawab\":[\"...\"],\"keputusan\":[\"...\"],\"action_items\":[[\"Penanggung Jawab\",\"Tindakan\"],[\"Tim A\",\"Melakukan ...\"]],\"penutup\":[\"...\"],\"penanda_tangan\":[[\"Pimpinan Kegiatan\",\"...\"],[\"Notulen\",\"...\"]],\"catatan\":[\"...\"]}. Use this instead of writing your own DOCX script.\n"
            ."ACTION: GENERATE_PPTX\n"
            ."```marp\n"
            ."<Marp Markdown — use --- to separate slides, frontmatter like `---\ntheme: default\nclass: invert\n---` to switch themes/dark mode. Include slide titles and brief bullets based on the user's request.>\n"
            ."```\n"
            ."Emit the ACTION line immediately followed by the fenced marp block.";
    }

    private static function todoAdd(int $userId, string $arg): string
    {
        $payload = self::jsonPayload($arg) ?? ['title' => $arg];
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return '✖ TODO_ADD butuh judul.';
        }

        $data = [
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 255),
            'priority' => in_array($payload['priority'] ?? null, ['tinggi', 'sedang', 'rendah'], true) ? $payload['priority'] : 'sedang',
        ];

        if (! empty($payload['due_at'])) {
            try {
                $data['due_at'] = \Carbon\Carbon::parse((string) $payload['due_at']);
            } catch (\Throwable) {
                return '✖ due_at tidak valid. Gunakan YYYY-MM-DD HH:MM.';
            }
        }
        if (isset($payload['notes'])) {
            $data['notes'] = mb_substr((string) $payload['notes'], 0, 2000);
        }
        if (isset($payload['durasi_jam']) && is_numeric($payload['durasi_jam'])) {
            $data['durasi_jam'] = min(24, max(0.25, (float) $payload['durasi_jam']));
        }
        if (isset($payload['tindak_lanjut'])) {
            $data['tindak_lanjut'] = mb_substr((string) $payload['tindak_lanjut'], 0, 2000);
        }

        $todo = Todo::create($data);
        $planIds = self::planIds($payload, $userId);
        TodoEventSync::syncPlans($todo, $planIds);

        $withEvent = ! empty($payload['sync_calendar']) && $todo->due_at;
        if ($withEvent) {
            TodoEventSync::attachEvent($todo);
        }

        return '✔ Todo ditambahkan: "'.$todo->title.'"'.
            ($planIds !== [] ? ' dengan '.count($planIds).' indikator.' : '').
            ($withEvent ? ' Masuk kalender.' : '');
    }

    private static function todosMatching(int $userId, string $arg)
    {
        $query = Todo::where('user_id', $userId);
        if (preg_match('/^#(\d+)$/', trim($arg), $m)) {
            $query->whereKey((int) $m[1]);
        } else {
            $query->where('title', 'like', '%'.$arg.'%');
        }

        return $query;
    }

    private static function todoDone(int $userId, string $arg): string
    {
        if ($arg === '') {
            return '✖ TODO_DONE butuh judul/id.';
        }
        $titles = [];
        foreach (self::todosMatching($userId, $arg)->get() as $todo) {
            $todo->update(['done' => true]);
            $titles[] = $todo->title;
        }

        return $titles === []
            ? '✖ Todo "'.$arg.'" tidak ditemukan.'
            : '✔ Todo selesai: "'.implode('", "', $titles).'".';
    }

    private static function todoDel(int $userId, string $arg): string
    {
        if ($arg === '') {
            return '✖ TODO_DEL butuh judul/id.';
        }
        $titles = [];
        foreach (self::todosMatching($userId, $arg)->get() as $todo) {
            $todo->delete();
            $titles[] = $todo->title;
        }

        return $titles === []
            ? '✖ Todo "'.$arg.'" tidak ditemukan.'
            : '✔ Todo dihapus: "'.implode('", "', $titles).'".';
    }

    private static function eventAdd(int $userId, string $arg): string
    {
        $payload = self::jsonPayload($arg);
        if ($payload === null) {
            if (! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[\sT](\d{1,2}):(\d{2}))?\s*\|\s*(.+?)\s*$/', $arg, $m)) {
                return '✖ Format EVENT_ADD salah. Gunakan: EVENT_ADD <YYYY-MM-DD HH:MM> | <judul>.';
            }
            [, $date, $h, $min] = $m;
            $payload = [
                'start_at' => sprintf('%s %02d:%02d:00', $date, $h !== '' ? (int) $h : 9, $min !== '' ? (int) $min : 0),
                'title' => $m[4],
            ];
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return '✖ EVENT_ADD butuh judul.';
        }
        if (empty($payload['start_at'])) {
            return '✖ EVENT_ADD butuh start_at (YYYY-MM-DD HH:MM).';
        }

        try {
            $start = \Carbon\Carbon::parse((string) $payload['start_at']);
            $end = ! empty($payload['end_at']) ? \Carbon\Carbon::parse((string) $payload['end_at']) : null;
        } catch (\Throwable) {
            return '✖ start_at/end_at tidak valid. Gunakan YYYY-MM-DD HH:MM.';
        }

        if (! $end) {
            $hours = isset($payload['durasi_jam']) && is_numeric($payload['durasi_jam'])
                ? min(24, max(0.25, (float) $payload['durasi_jam']))
                : 1;
            $end = $start->copy()->addMinutes((int) round($hours * 60));
        } elseif ($end->lte($start)) {
            return '✖ end_at harus setelah start_at.';
        }

        $data = [
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 255),
            'start_at' => $start,
            'end_at' => $end,
            'durasi_jam' => round($start->diffInMinutes($end) / 60, 2),
        ];
        if (isset($payload['description']) || isset($payload['notes'])) {
            $data['description'] = mb_substr((string) ($payload['description'] ?? $payload['notes']), 0, 2000);
        }
        if (isset($payload['location'])) {
            $data['location'] = mb_substr((string) $payload['location'], 0, 255);
        }
        if (isset($payload['tindak_lanjut'])) {
            $data['tindak_lanjut'] = mb_substr((string) $payload['tindak_lanjut'], 0, 2000);
        }

        $event = Event::create($data);
        $planIds = self::planIds($payload, $userId);
        TodoEventSync::syncPlans($event, $planIds);

        $withTodo = ! empty($payload['sync_todo']);
        if ($withTodo) {
            TodoEventSync::attachTodo($event);
        }

        return '✔ Event dibuat: "'.$event->title.'" pada '.$event->start_at->format('d M Y, H:i').
            ($planIds !== [] ? ' dengan '.count($planIds).' indikator.' : '').
            ($withTodo ? ' Masuk TodoList.' : '.').'';
    }

    private static function jsonPayload(string $arg): ?array
    {
        $trim = trim($arg);
        if ($trim === '' || $trim[0] !== '{') {
            return null;
        }
        $data = json_decode($trim, true);

        return is_array($data) ? $data : null;
    }

    private static function planIds(array $payload, int $userId): array
    {
        $ids = $payload['performance_plan_ids'] ?? null;
        if (! is_array($ids)) {
            $ids = isset($payload['performance_plan_id']) ? [$payload['performance_plan_id']] : [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $allowed = PerformancePlan::where(function ($q) use ($userId) {
            $q->whereNull('user_id')->orWhere('user_id', $userId);
        })->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($ids, $allowed));
    }

    private static function eventDel(int $userId, string $arg): string
    {
        $query = Event::where('user_id', $userId);
        if (preg_match('/^#(\d+)$/', trim($arg), $m)) {
            $query->whereKey((int) $m[1]);
        } else {
            $query->where('title', 'like', '%'.$arg.'%');
        }
        $titles = [];
        foreach ($query->get() as $event) {
            $event->delete();
            $titles[] = $event->title;
        }

        return $titles === []
            ? '✖ Event "'.$arg.'" tidak ditemukan.'
            : '✔ Event dihapus: "'.implode('", "', $titles).'".';
    }

    private static function pptxBlock(array $lines, int $i): array
    {
        $n = count($lines);
        $j = $i + 1;
        while ($j < $n && trim($lines[$j]) === '') {
            $j++;
        }

        if ($j < $n && preg_match('/^\s*```[a-z]*\s*$/i', $lines[$j])) {
            $j++;
            $buf = [];
            while ($j < $n && ! preg_match('/^\s*```\s*$/', $lines[$j])) {
                $buf[] = $lines[$j++];
            }
            if ($j < $n) {
                $j++;
            }

            return [trim(implode("\n", $buf)), $j - 1];
        }

        $buf = [];
        while ($j < $n && ! preg_match('/^\s*ACTION:/i', $lines[$j])) {
            $buf[] = $lines[$j++];
        }

        return [trim(implode("\n", $buf)), $j - 1];
    }

    private static function generatePptx(int $userId, string $arg, ?int $sessionId): string
    {
        $md = $arg;
        if (strpos($md, '---') === false && trim($md) === '') {
            $md = '';
        }
        if (trim($md) === '') {
            return '✖ GENERATE_PPTX butuh konten Markdown Marp.';
        }

        try {
            $media = MarpGenerator::generatePptx($md, $userId, $sessionId);

            return '✔ Presentasi PPTX dibuat: ['.$media->filename.'](/api/media/'.$media->id.')';
        } catch (ProcessFailedException $e) {
            return '✖ Gagal membuat PPTX: '.preg_replace('/[\r\n]+/', ' ', $e->getMessage());
        } catch (\Throwable $e) {
            return '✖ Gagal membuat PPTX: '.$e->getMessage();
        }
    }

    private static function docBuild(int $userId, string $arg, ?int $sessionId): string
    {
        $doc = json_decode($arg, true);
        if (! is_array($doc)) {
            return '✖ DOC_BUILD butuh JSON valid dalam satu baris.';
        }

        try {
            $media = DocumentBuilder::save($doc, $userId, $sessionId);
        } catch (\Throwable $e) {
            return '✖ Gagal membuat dokumen: '.$e->getMessage();
        }

        return '✔ Dokumen dibuat: ['.$media->filename.'](/api/media/'.$media->id.')';
    }

    private static function notulenBuild(int $userId, string $arg, ?int $sessionId): string
    {
        $doc = json_decode($arg, true);
        if (! is_array($doc)) {
            return '✖ NOTULEN_BUILD butuh JSON valid dalam satu baris.';
        }

        try {
            $media = NotulenBuilder::save($doc, $userId, $sessionId);
        } catch (\Throwable $e) {
            return '✖ Gagal membuat notulen: '.$e->getMessage();
        }

        return '✔ Notulen dibuat: ['.$media->filename.'](/api/media/'.$media->id.')';
    }

    private static function stripActions(string $text): string
    {
        $keep = array_filter(preg_split('/\R/', $text) ?: [], fn ($l) => ! preg_match('/^\s*ACTION:\s/i', $l));

        return trim(implode("\n", $keep), "\n");
    }
}