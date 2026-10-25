<?php

namespace App\Support;

use App\Models\Event;
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

        $keep = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*ACTION:\s*([A-Z_]+)\s*(.*?)\s*$/i', $line, $m)) {
                $cmd = strtoupper($m[1]);
                $arg = trim($m[2] ?? '');
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
        $s .= "CURRENT USER STATE — todo list & upcoming events (private data; use it to answer, but do not dump the entire list unless the user asks).\n";
        $s .= 'Todos (open only): '.($todos->isEmpty() ? 'none' : $todos->map(fn ($t) => sprintf('#%d "%s"', $t->id, $t->title))->join('; '))."\n";
        $s .= 'Events (upcoming): '.($events->isEmpty() ? 'none' : $events->map(fn ($e) => sprintf('%s %s "%s" (#%d)', $e->start_at->toDateString(), $e->start_at->format('H:i'), $e->title, $e->id))->join('; '));

        return $s;
    }

    public static function toolsInstruction(): string
    {
        return "\n\nYou can also manage the user's Todo list and Calendar by emitting exact ACTION lines (one per operation). Never print ACTION lines as normal text — emit them, then tell the user what you did:\n"
            ."ACTION: TODO_ADD <title>\n"
            ."ACTION: TODO_DONE <todo title or #id>\n"
            ."ACTION: TODO_DEL <todo title or #id>\n"
            ."ACTION: EVENT_ADD <YYYY-MM-DD HH:MM> | <title>\n"
            ."ACTION: EVENT_DEL <#id>\n"
            ."ACTION: DOC_BUILD <single-line JSON> — build an XLSX spreadsheet and send it to the chat/Gallery. Schema: {\"filename\":\"name.xlsx\",\"sheet\":\"Sheet\",\"title\":\"Report title\",\"blocks\":[{\"heading\":\"Section\",\"columns\":[\"Col A\",\"Col B\"],\"rows\":[[\"a1\",\"b1\"],[\"a2\",\"b2\"]]}]}. Use multiple blocks for several titled tables/lists.\n"
            ."ACTION: NOTULEN_BUILD <single-line JSON> — build a DOCX notulen rapat (pola Dinas Pendidikan Kaltim: kop, judul, identitas, ringkasan, agenda, pokok pembahasan, tanya-jawab, keputusan, action items, penutup) and send it to the chat/Gallery. Schema: {\"filename\":\"Notulen.docx\",\"judul\":\"NOTULENSI RAPAT\",\"subjudul\":\"Rapat Koordinasi BOSP\",\"identitas\":{\"Hari/Tanggal\":\"Selasa, 20 Oktober 2026\",\"Waktu\":\"09.00 WITA\",\"Tempat\":\"Daring via Zoom\",\"Moderator\":\"...\",\"Peserta\":\"...\"},\"ringkasan\":[\"...\"],\"agenda\":[\"...\"],\"pembahasan\":[{\"judul\":\"A. ...\",\"paragraf\":[\"...\"],\"poin\":[\"...\"]}],\"tanya_jawab\":[\"...\"],\"keputusan\":[\"...\"],\"action_items\":[[\"Penanggung Jawab\",\"Tindakan\"],[\"Tim A\",\"Melakukan ...\"]],\"penutup\":[\"...\"],\"penanda_tangan\":[[\"Pimpinan Kegiatan\",\"...\"],[\"Notulen\",\"...\"]],\"catatan\":[\"...\"]}. Use this instead of writing your own DOCX script.\n"
            ."ACTION: GENERATE_PPTX\n"
            ."<Marp Markdown — use --- to separate slides, frontmatter like `---\ntheme: default\nclass: invert\n---` to switch themes/dark mode. Include slide titles and brief bullets based on the user's request. The entire Marp content goes after this line on the next lines.> Emit the ACTION line and the Markdown immediately after it.";
    }

    private static function todoAdd(int $userId, string $arg): string
    {
        if ($arg === '') {
            return '✖ TODO_ADD butuh judul.';
        }
        $todo = Todo::create(['user_id' => $userId, 'title' => mb_substr($arg, 0, 255)]);

        return '✔ Todo ditambahkan: "'.$todo->title.'".';
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
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[\sT](\d{1,2}):(\d{2}))?\s*\|\s*(.+?)\s*$/', $arg, $m)) {
            return '✖ Format EVENT_ADD salah. Gunakan: EVENT_ADD <YYYY-MM-DD HH:MM> | <judul>.';
        }
        [, $date, $h, $min] = $m;
        $start = \Carbon\Carbon::parse(sprintf('%s %02d:%02d:00', $date, $h !== '' ? (int) $h : 9, $min !== '' ? (int) $min : 0));
        $title = mb_substr($m[4], 0, 255);
        $event = Event::create([
            'user_id' => $userId,
            'title' => $title,
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
        ]);

        return '✔ Event dibuat: "'.$event->title.'" pada '.$event->start_at->format('d M Y, H:i').'.';
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