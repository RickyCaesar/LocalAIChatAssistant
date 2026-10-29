<?php

// Self-check sinkronisasi Todo/Kalender, template, matriks, dan DOCX e-SAKIP.
// Run: docker exec ai-chat-backend php tests/performance_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Models\Event;
use App\Models\Media;
use App\Models\PerformancePlan;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$call = static function (string $method, string $uri, ?string $token, array $data = []) use ($kernel): array {
    $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'] + ($token ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : []),
        $data === [] ? null : json_encode($data)
    );
    if ($data !== []) {
        $request->headers->set('Content-Type', 'application/json');
    }
    $response = $kernel->handle($request);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
};

$fail = static function (string $message, array $body = []): void {
    throw new RuntimeException($message.($body ? ' '.json_encode($body) : ''));
};

$user = null;
$secondUser = null;
$reportMedia = null;
$pngPath = null;
$templatePlanCreated = false;
$error = null;

try {
    $user = User::create([
        'name' => 'Performance Check',
        'email' => 'performance-check-'.bin2hex(random_bytes(4)).'@local.test',
        'password' => Hash::make('secret123'),
        'api_token' => 'check-'.bin2hex(random_bytes(8)),
    ]);
    $token = $user->api_token;
    $month = now()->format('Y-m');

    [$status, $body] = $call('PUT', '/api/profile', $token, ['nip' => '198001012005011001', 'jabatan' => 'Analis Uji']);
    if ($status !== 200 || ($body['user']['nip'] ?? null) !== '198001012005011001' || ($body['user']['jabatan'] ?? null) !== 'Analis Uji') {
        $fail('simpan NIP/jabatan profil gagal', $body);
    }

    [$status, $body] = $call('GET', '/api/performance/plans', $token);
    $plan = $body['plans'][0] ?? null;
    if ($status !== 200 || count($body['plans'] ?? []) !== 5 || ! $plan) {
        $fail('daftar 5 indikator gagal', $body);
    }
    if (! PerformancePlan::where('slug', 'dssd')->exists()) {
        PerformancePlan::create(['name' => 'DSSD Check', 'slug' => 'dssd']);
        $templatePlanCreated = true;
    }

    $media = collect(['bukti-check.jpg', 'bukti-check-2.jpg', 'bukti-check-3.jpg'])->map(fn ($name) => Media::create([
        'user_id' => $user->id,
        'role' => 'assistant',
        'kind' => 'image',
        'filename' => $name,
        'mime' => 'image/jpeg',
        'size' => 100,
        'path' => 'media/check/'.$name,
        'preview' => null,
        'model' => 'check',
    ]));
    $media = $media->values();
    $primary = $media->first();

    $pngPath = storage_path('app/private/media/check/bukti-check.png');
    @mkdir(dirname($pngPath), 0775, true);
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z4p8AAAAASUVORK5CYII='));
    $media[0]->update(['filename' => 'bukti-check.png', 'mime' => 'image/png', 'path' => 'media/check/bukti-check.png']);

    [$status, $body] = $call('POST', '/api/todos', $token, [
        'title' => 'Uji e-SAKIP',
        'due_at' => $month.'-15T09:00:00+08:00',
        'priority' => 'tinggi',
        'notes' => 'Catatan lengkap kegiatan uji',
        'durasi_jam' => 2.5,
        'performance_plan_id' => $plan['id'],
        'media_ids' => $media->pluck('id')->all(),
        'tindak_lanjut' => 'Catatan awal',
        'sync_calendar' => true,
    ]);
    $todoId = $body['todo']['id'] ?? null;
    $eventId = $body['todo']['event_id'] ?? null;
    if ($status !== 201 || ! $todoId || ! $eventId
        || count($body['todo']['media_ids'] ?? []) !== 3
        || count($body['todo']['media_items'] ?? []) !== 3) {
        $fail('todo + event gagal dibuat', $body);
    }

    $event = Event::find($eventId);
    if (! $event || $event->start_at->format('Y-m-d H:i') !== $month.'-15 09:00'
        || $event->end_at->format('Y-m-d H:i') !== $month.'-15 11:30'
        || abs($event->durasi_jam - 2.5) > 0.01
        || $event->performance_plan_id != $plan['id'] || $event->media_id != $primary->id
        || count($event->media_ids ?? []) !== 3) {
        $fail('field event hasil sinkronisasi tidak sesuai');
    }

    [$status, $body] = $call('PUT', '/api/todos/'.$todoId, $token, [
        'title' => 'Uji e-SAKIP revisi',
        'due_at' => $month.'-16T16:00:00+08:00',
        'durasi_jam' => 3,
    ]);
    $event->refresh();
    if ($status !== 200 || $event->title !== 'Uji e-SAKIP revisi'
        || $event->start_at->format('Y-m-d H:i') !== $month.'-16 16:00'
        || $event->end_at->format('Y-m-d H:i') !== $month.'-16 19:00'
        || abs($event->durasi_jam - 3) > 0.01) {
        $fail('ubah todo tidak memperbarui event', $body);
    }

    [$status, $body] = $call('POST', '/api/todos/'.$todoId.'/complete', $token, [
        'tindak_lanjut' => 'Selesai diuji',
        'media_ids' => $media->pluck('id')->all(),
    ]);
    $event->refresh();
    if ($status !== 200 || ! Todo::find($todoId)->done || ! $event->todo_id
        || $event->tindak_lanjut !== 'Selesai diuji') {
        $fail('complete todo tidak menyinkronkan event', $body);
    }

    [$status, $body] = $call('POST', '/api/events', $token, [
        'title' => 'Kegiatan Kalender Mandiri',
        'start_at' => $month.'-18T08:00:00+08:00',
        'end_at' => $month.'-18T09:30:00+08:00',
        'description' => 'Deskripsi lengkap kegiatan kalender',
        'location' => 'Ruang Rapat',
        'performance_plan_id' => $plan['id'],
        'media_ids' => $media->pluck('id')->all(),
    ]);
    if ($status !== 201 || ($body['event']['todo_id'] ?? null) !== null) {
        $fail('acara mandiri ber-indikator gagal dibuat', $body);
    }

    [$status, $body] = $call('GET', '/api/performance/report?month='.$month, $token);
    $row = collect($body['plans'] ?? [])->firstWhere('id', (int) $plan['id']);
    if ($status !== 200 || ($body['totals']['activities'] ?? 0) < 2
        || ! $row || $row['activities'] < 2 || $row['evidence'] < 2 || $row['done'] < 1
        || ! in_array('bukti-check-2.jpg', $row['evidence_files'] ?? [], true)) {
        $fail('matriks laporan tidak menghitung Todo + acara mandiri', $body);
    }

    $hermesConfigured = (config('services.hermes.base_url') ?: env('HERMES_BASE_URL'))
        && (config('services.hermes.key') ?: env('HERMES_API_KEY'));
    if (! $hermesConfigured) {
        [$status, $body] = $call('POST', '/api/performance/note', $token, ['month' => $month]);
        $note = (string) ($body['note'] ?? '');
        $missing = array_values(array_filter(
            [$plan['name'], $plan['indikator_kinerja'] ?: $plan['name'], 'Ringkasan capaian', 'Analisis performa', 'Analisis tindak lanjut', 'Rekomendasi', 'Kesimpulan'],
            fn ($needle) => ! str_contains($note, $needle)
        ));
        if ($status !== 200 || $missing !== [] || str_contains($note, 'bukti-check') || substr_count($note, "\n\n") < 5 || strlen($note) < 700) {
            $fail('catatan belum berupa rangkuman terstruktur atau menyebut nama file', ['missing' => $missing, 'note' => $note]);
        }
    }

    $secondUser = User::create([
        'name' => 'Pengguna Lain',
        'email' => 'performance-other-'.bin2hex(random_bytes(4)).'@local.test',
        'password' => Hash::make('secret123'),
        'api_token' => 'check-other-'.bin2hex(random_bytes(8)),
    ]);
    $secondToken = $secondUser->api_token;
    $nextMonth = date('Y-m', strtotime($month.'-01 +1 month'));
    [$status, $body] = $call('GET', '/api/performance/report?month='.$nextMonth, $token);
    $nextPlanIds = collect($body['plans'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
    if ($status !== 200 || ! $nextPlanIds->contains((int) $plan['id'])) {
        $fail('indikator yang pernah dipakai hilang saat ganti bulan', ['plans' => $nextPlanIds->all()]);
    }

    [$status, $body] = $call('POST', '/api/performance/plans', $secondToken, ['name' => 'Indikator Pengguna Lain']);
    $secondPlanId = $body['plan']['id'] ?? null;
    if ($status !== 201 || ! $secondPlanId) {
        $fail('indikator pengguna kedua gagal dibuat', $body);
    }

    [$status, $body] = $call('GET', '/api/performance/report?month='.$month, $token);
    $ownPlanIds = collect($body['plans'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
    if ($status !== 200 || $ownPlanIds->contains((int) $secondPlanId) || ! $ownPlanIds->contains((int) $plan['id']) || $ownPlanIds->count() !== 1) {
        $fail('matriks pengguna pertama memuat indikator pengguna lain atau indikator global yang tidak dipakai', ['plans' => $ownPlanIds->all()]);
    }

    [$status, $body] = $call('POST', '/api/todos', $token, [
        'title' => 'Coba indikator pengguna lain',
        'due_at' => $month.'-19T09:00:00+08:00',
        'performance_plan_id' => $secondPlanId,
    ]);
    if ($status !== 422) {
        $fail('pengguna bisa menghubungkan todo ke indikator pengguna lain', $body);
    }

    [$status, $body] = $call('POST', '/api/events', $token, [
        'title' => 'Coba indikator pengguna lain di kalender',
        'start_at' => $month.'-19T10:00:00+08:00',
        'end_at' => $month.'-19T11:00:00+08:00',
        'performance_plan_id' => $secondPlanId,
    ]);
    if ($status !== 422) {
        $fail('pengguna bisa menghubungkan event ke indikator pengguna lain', $body);
    }

    [$status, $body] = $call('GET', '/api/performance/report?month='.$month, $secondToken);
    $otherPlanIds = collect($body['plans'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
    if ($status !== 200 || ! $otherPlanIds->contains((int) $secondPlanId) || $otherPlanIds->contains((int) $plan['id']) || $otherPlanIds->count() !== 1) {
        $fail('matriks pengguna kedua memuat indikator pengguna lain', ['plans' => $otherPlanIds->all()]);
    }

    [$status, $body] = $call('POST', '/api/performance/report', $token, ['month' => $month, 'base_url' => 'https://contoh.test', 'pimpinan_nama' => 'Pimpinan Uji', 'pimpinan_jabatan' => 'Kepala Dinas Uji', 'pimpinan_nip' => '197001011995031002']);
    $reportId = $body['media']['id'] ?? null;
    if ($status !== 201 || ! $reportId) {
        $fail('generate PDF gagal', $body);
    }
    $reportMedia = Media::find($reportId);
    $full = storage_path('app/private/'.$reportMedia->path);
    $xml = (string) shell_exec('pdftotext '.escapeshellarg($full).' - 2>/dev/null');
    $missing = array_values(array_filter(
        ['LAPORAN KINERJA BULANAN', 'Uji e-SAKIP revisi', 'Kegiatan Kalender Mandiri', 'Selesai diuji', $plan['name'], 'CATATAN', 'Mengetahui,', 'Pimpinan Uji', 'Kepala Dinas Uji', 'NIP. 197001011995031002', 'Penanggung Jawab,', 'NIP. 198001012005011001', 'Analis Uji', 'LAMPIRAN BERKAS', 'Kegiatan 1', 'bukti-check.png', 'bukti-check-2.jpg'],
        fn ($needle) => ! str_contains($xml, $needle)
    ));
    if ($missing !== []) {
        $fail('isi PDF tidak lengkap', ['missing' => $missing, 'text' => substr($xml, 0, 500)]);
    }
    $images = (string) shell_exec('pdfimages -list '.escapeshellarg($full).' 2>/dev/null');
    if (substr_count(trim($images), "\n") < 2) {
        $fail('gambar lampiran tidak tersemat di PDF', ['pdfimages' => $images]);
    }
    $links = (string) shell_exec('pdftohtml -xml -stdout -i '.escapeshellarg($full).' 2>/dev/null');
    if (! str_contains($links, 'https://contoh.test/api/media/')) {
        $fail('hyperlink lampiran tidak memakai base URL klien');
    }
    $pages = (int) trim((string) shell_exec('pdfinfo '.escapeshellarg($full).' 2>/dev/null | awk \'/^Pages:/{print $2}\''));
    $lastPage = (string) shell_exec('pdftotext -f '.$pages.' -l '.$pages.' '.escapeshellarg($full).' - 2>/dev/null');
    if ($pages < 2 || (! str_contains($lastPage, 'LAMPIRAN BERKAS') && ! str_contains($lastPage, 'bukti-check')) || str_contains($lastPage, 'D. CATATAN')) {
        $fail('lampiran bukan halaman terakhir atau catatan masih di halaman lampiran', ['pages' => $pages, 'last' => substr($lastPage, 0, 300)]);
    }

    [$status, $body] = $call('POST', '/api/performance/templates/penginputan-dssd-bulanan', $token, ['month' => $month]);
    $templateTodoId = $body['todo']['id'] ?? null;
    if ($status !== 201 || ! $templateTodoId || ! $body['todo']['event_id']) {
        $fail('template tidak membuat todo + event', $body);
    }
    $templateTodo = Todo::find($templateTodoId);
    $templateEvent = Event::find($templateTodo->event_id);
    if (! $templateTodo || ! $templateEvent
        || $templateTodo->performancePlan?->slug !== 'dssd'
        || $templateEvent->start_at->format('Y-m-d H:i') !== $month.'-01 09:00') {
        $fail('field template DSSD tidak sesuai');
    }

    [$status, $body] = $call('POST', '/api/events', $token, [
        'title' => 'Rapat e-SAKIP',
        'start_at' => $month.'-20T10:00:00+08:00',
        'end_at' => $month.'-20T12:00:00+08:00',
        'performance_plan_id' => $plan['id'],
        'media_ids' => $media->pluck('id')->all(),
        'sync_todo' => true,
    ]);
    $fromEventId = $body['event']['id'] ?? null;
    $fromEventTodoId = $body['event']['todo_id'] ?? null;
    if ($status !== 201 || ! $fromEventId || ! $fromEventTodoId) {
        $fail('event + todo gagal dibuat', $body);
    }
    $fromEvent = Event::find($fromEventId);
    $fromEventTodo = Todo::find($fromEventTodoId);
    if (! $fromEventTodo || $fromEventTodo->due_at->format('Y-m-d H:i') !== $month.'-20 10:00'
        || abs($fromEventTodo->durasi_jam - 2) > 0.01 || abs($fromEvent->durasi_jam - 2) > 0.01
        || count($fromEventTodo->media_ids ?? []) !== 3) {
        $fail('todo hasil sinkronisasi event tidak sesuai');
    }

    [$status, $body] = $call('PUT', '/api/events/'.$fromEventId, $token, [
        'title' => 'Rapat e-SAKIP revisi',
        'start_at' => $month.'-20T14:00:00+08:00',
        'end_at' => $month.'-20T16:30:00+08:00',
    ]);
    $fromEventTodo->refresh();
    $fromEvent->refresh();
    if ($status !== 200 || $fromEventTodo->title !== 'Rapat e-SAKIP revisi'
        || $fromEventTodo->due_at->format('Y-m-d H:i') !== $month.'-20 14:00'
        || abs($fromEventTodo->durasi_jam - 2.5) > 0.01 || abs($fromEvent->durasi_jam - 2.5) > 0.01) {
        $fail('ubah event tidak memperbarui todo', $body);
    }

    [$status, $body] = $call('PUT', '/api/events/'.$fromEventId, $token, ['done' => true]);
    $fromEventTodo->refresh();
    if ($status !== 200 || ! $fromEventTodo->done || ! ($body['event']['done'] ?? false)) {
        $fail('status selesai dari kalender tidak tersinkron', $body);
    }

    [$status] = $call('DELETE', '/api/events/'.$fromEventId, $token);
    if ($status !== 200 || Todo::find($fromEventTodoId)) {
        $fail('hapus event tidak menghapus todo');
    }

    [$status] = $call('DELETE', '/api/todos/'.$todoId, $token);
    if ($status !== 200 || Event::find($eventId)) {
        $fail('hapus todo tidak menghapus event');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($reportMedia) {
        @unlink(storage_path('app/private/'.$reportMedia->path));
        Media::whereKey($reportMedia->id)->delete();
    }
    if ($pngPath) {
        @unlink($pngPath);
    }
    if ($templatePlanCreated) {
        PerformancePlan::where('slug', 'dssd')->delete();
    }
    if ($secondUser) {
        PerformancePlan::where('user_id', $secondUser->id)->delete();
        $secondUser->delete();
    }
    if ($user) {
        User::whereKey($user->id)->delete();
    }
}

if ($error !== null) {
    fwrite(STDERR, "FAIL: $error\n");
    exit(1);
}

echo "OK: sinkronisasi Todo-Kalender, multi-media, template, matriks, PDF\n";
