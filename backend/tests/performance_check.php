<?php

// Self-check sinkronisasi Todo/Kalender, template, matriks, dan DOCX e-SAKIP.
// Run: docker exec ai-chat-backend php tests/performance_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Models\Event;
use App\Models\Media;
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
$reportMedia = null;
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

    [$status, $body] = $call('GET', '/api/performance/plans', $token);
    $plan = $body['plans'][0] ?? null;
    if ($status !== 200 || count($body['plans'] ?? []) !== 5 || ! $plan) {
        $fail('daftar 5 indikator gagal', $body);
    }

    $media = Media::create([
        'user_id' => $user->id,
        'role' => 'assistant',
        'kind' => 'image',
        'filename' => 'bukti-check.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
        'path' => 'media/check/bukti-check.jpg',
        'preview' => null,
        'model' => 'check',
    ]);

    [$status, $body] = $call('POST', '/api/todos', $token, [
        'title' => 'Uji e-SAKIP',
        'due_at' => $month.'-15T09:00:00+08:00',
        'priority' => 'tinggi',
        'durasi_jam' => 2.5,
        'performance_plan_id' => $plan['id'],
        'media_id' => $media->id,
        'tindak_lanjut' => 'Catatan awal',
        'sync_calendar' => true,
    ]);
    $todoId = $body['todo']['id'] ?? null;
    $eventId = $body['todo']['event_id'] ?? null;
    if ($status !== 201 || ! $todoId || ! $eventId) {
        $fail('todo + event gagal dibuat', $body);
    }

    $event = Event::find($eventId);
    if (! $event || $event->start_at->format('Y-m-d H:i') !== $month.'-15 09:00'
        || $event->end_at->format('Y-m-d H:i') !== $month.'-15 11:30'
        || abs($event->durasi_jam - 2.5) > 0.01
        || $event->performance_plan_id != $plan['id'] || $event->media_id != $media->id) {
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
        'media_id' => $media->id,
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
        'performance_plan_id' => $plan['id'],
        'media_id' => $media->id,
    ]);
    if ($status !== 201 || ($body['event']['todo_id'] ?? null) !== null) {
        $fail('acara mandiri ber-indikator gagal dibuat', $body);
    }

    [$status, $body] = $call('GET', '/api/performance/report?month='.$month, $token);
    $row = collect($body['plans'] ?? [])->firstWhere('id', (int) $plan['id']);
    if ($status !== 200 || ($body['totals']['activities'] ?? 0) < 2
        || ! $row || $row['activities'] < 2 || $row['evidence'] < 2 || $row['done'] < 1) {
        $fail('matriks laporan tidak menghitung Todo + acara mandiri', $body);
    }

    [$status, $body] = $call('POST', '/api/performance/report', $token, ['month' => $month]);
    $reportId = $body['media']['id'] ?? null;
    if ($status !== 201 || ! $reportId) {
        $fail('generate DOCX gagal', $body);
    }
    $reportMedia = Media::find($reportId);
    $full = storage_path('app/private/'.$reportMedia->path);
    $zip = new ZipArchive();
    $xml = '';
    if ($zip->open($full) === true) {
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
    }
    if (! str_contains($xml, 'LAPORAN KINERJA BULANAN')
        || ! str_contains($xml, 'Uji e-SAKIP revisi')
        || ! str_contains($xml, 'Kegiatan Kalender Mandiri')
        || ! str_contains($xml, 'Selesai diuji')
        || ! str_contains($xml, $plan['name'])) {
        $fail('isi DOCX tidak lengkap');
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
        'media_id' => $media->id,
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
        || abs($fromEventTodo->durasi_jam - 2) > 0.01 || abs($fromEvent->durasi_jam - 2) > 0.01) {
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
    if ($user) {
        User::whereKey($user->id)->delete();
    }
}

if ($error !== null) {
    fwrite(STDERR, "FAIL: $error\n");
    exit(1);
}

echo "OK: sinkronisasi Todo-Kalender, template, matriks, DOCX e-SAKIP\n";
