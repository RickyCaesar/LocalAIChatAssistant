<?php

// Self-check rentang grafik analitik dashboard (7/30/90/365 hari).
// Butuh aplikasi + DB; membuat admin sementara lalu menghapusnya.
// Run: docker exec ai-chat-backend php tests/analytics_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$call = static function (string $uri, string $token) use ($kernel): array {
    $request = Request::create($uri, 'GET', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);
    $response = $kernel->handle($request);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
};

$user = null;
$error = null;

try {
    $user = User::create([
        'name' => 'Analytics Check',
        'email' => 'analytics-check-'.bin2hex(random_bytes(4)).'@local.test',
        'password' => Hash::make('secret123'),
        'api_token' => 'check-'.bin2hex(random_bytes(8)),
        'is_admin' => 1,
    ]);

    foreach ([7, 30, 90, 365] as $days) {
        [$status, $body] = $call('/api/analytics?days='.$days, $user->api_token);
        $dates = array_column($body['by_day'] ?? [], 'date');
        if ($status !== 200
            || ($body['range_days'] ?? null) !== $days
            || count($dates) !== $days
            || $dates[0] !== now()->subDays($days - 1)->toDateString()
            || end($dates) !== now()->toDateString()) {
            throw new RuntimeException("rentang $days hari tidak sesuai");
        }
    }

    [$status, $body] = $call('/api/analytics?days=999', $user->api_token);
    if ($status !== 200 || ($body['range_days'] ?? null) !== 7 || count($body['by_day'] ?? []) !== 7) {
        throw new RuntimeException('rentang tidak valid seharusnya fallback ke 7 hari');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($user) {
        User::whereKey($user->id)->delete();
    }
}

if ($error !== null) {
    fwrite(STDERR, "FAIL: $error\n");
    exit(1);
}

echo "OK: rentang analitik 7/30/90/365\n";
