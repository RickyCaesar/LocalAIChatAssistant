<?php

// Self-check zona waktu WITA: app timezone, konteks tanggal untuk Hermes,
// parsing "besok" oleh ActionRunner, dan konversi ISO dari client.
// Butuh aplikasi + DB; membuat user sementara lalu menghapusnya.
// Run: docker exec ai-chat-backend php tests/timezone_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Models\Event;
use App\Models\Todo;
use App\Models\User;
use App\Support\ActionRunner;
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

$fail = static function (string $message): void {
    throw new RuntimeException($message);
};

$user = null;
$eventId = null;
$todoId = null;
$error = null;

try {
    if (now()->getTimezone()->getName() !== 'Asia/Makassar') {
        $fail('app timezone bukan Asia/Makassar, dapat: '.now()->getTimezone()->getName());
    }

    $email = 'tz-check-'.bin2hex(random_bytes(4)).'@local.test';
    $user = User::create([
        'name' => 'TZ Check',
        'email' => $email,
        'password' => Hash::make('secret123'),
        'api_token' => 'tz-'.bin2hex(random_bytes(8)),
    ]);

    $snapshot = ActionRunner::stateSnapshot($user->id);
    $today = now()->toDateString();
    $besok = now()->copy()->addDay()->toDateString();
    if (! str_contains($snapshot, 'WITA') || ! str_contains($snapshot, $today) || ! str_contains($snapshot, $besok)) {
        $fail('snapshot Hermes tidak memuat WITA/hari ini/besok');
    }

    // "besok jam 09:00" dari Hermes harus tersimpan sebagai 09:00 WITA.
    $besokHari = now()->copy()->addDay()->toDateString();
    [$text, $results] = ActionRunner::run("ACTION: EVENT_ADD {$besokHari} 09:00 | Cek besok WITA", $user->id);
    $event = Event::where('user_id', $user->id)->where('title', 'Cek besok WITA')->first();
    if (! $event) {
        $fail('EVENT_ADD besok gagal dibuat: '.implode(' | ', $results));
    }
    $eventId = $event->id;
    if ($event->start_at->format('Y-m-d H:i') !== "{$besokHari} 09:00" || $event->start_at->getTimezone()->getName() !== 'Asia/Makassar') {
        $fail('EVENT_ADD tidak menghasilkan 09:00 WITA, dapat: '.$event->start_at->toIso8601String());
    }

    $token = $user->api_token;

    // Client mengirim instan UTC (01:00Z = 09:00 WITA) → response harus 09:00+08:00.
    [$status, $body] = $call('POST', '/api/events', $token, [
        'title' => 'Cek event client',
        'start_at' => '2026-12-01T01:00:00.000Z',
    ]);
    if ($status !== 201 || ($body['event']['start_at'] ?? null) !== '2026-12-01T09:00:00+08:00') {
        $fail('event dari ISO UTC salah: '.json_encode($body));
    }
    $clientEventId = $body['event']['id'];
    $call('DELETE', '/api/events/'.$clientEventId, $token);

    [$status, $body] = $call('POST', '/api/todos', $token, [
        'title' => 'Cek todo client',
        'due_at' => '2026-12-01T01:00:00.000Z',
    ]);
    if ($status !== 201 || ($body['todo']['due_at'] ?? null) !== '2026-12-01T09:00:00+08:00') {
        $fail('todo due_at dari ISO UTC salah: '.json_encode($body));
    }
    $todoId = $body['todo']['id'];
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($eventId) {
        Event::whereKey($eventId)->delete();
    }
    if ($todoId) {
        Todo::whereKey($todoId)->delete();
    }
    if ($user) {
        Event::where('user_id', $user->id)->delete();
        Todo::where('user_id', $user->id)->delete();
        User::whereKey($user->id)->delete();
    }
}

if ($error !== null) {
    fwrite(STDERR, "FAIL: $error\n");
    exit(1);
}

echo "OK: zona waktu WITA, konteks Hermes, dan konversi tanggal client\n";
