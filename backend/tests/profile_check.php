<?php

// Self-check endpoint profil (ubah nama/email/password) & proteksi settings.
// Butuh aplikasi + DB; membuat user sementara lalu menghapusnya.
// Run: docker exec ai-chat-backend php tests/profile_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Models\Setting;
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

$fail = static function (string $message): void {
    throw new RuntimeException($message);
};

$user = null;
$oldPalette = Setting::get('theme_palette');
$oldDark = Setting::get('theme_dark');
$error = null;

try {
    [$status] = $call('POST', '/api/register', null, ['name' => 'Check', 'email' => 'register-check@local.test', 'password' => 'secret123']);
    if ($status !== 404) {
        $fail('registrasi publik masih terbuka');
    }

    $email = 'profile-check-'.bin2hex(random_bytes(4)).'@local.test';
    $user = User::create([
        'name' => 'Check',
        'email' => $email,
        'password' => Hash::make('secret123'),
        'api_token' => 'check-'.bin2hex(random_bytes(8)),
    ]);
    $token = $user->api_token;

    [$status, $body] = $call('PUT', '/api/profile', $token, ['name' => 'Check Baru', 'email' => 'baru-'.$email]);
    $fresh = User::find($user->id);
    if ($status !== 200 || ($body['user']['name'] ?? null) !== 'Check Baru' || $fresh->email !== 'baru-'.$email) {
        $fail('ubah nama/email gagal');
    }

    [$status] = $call('PUT', '/api/profile', $token, ['current_password' => 'salah', 'new_password' => 'rahasia456']);
    if ($status !== 422) {
        $fail('password lama salah seharusnya 422');
    }
    if (! Hash::check('secret123', User::find($user->id)->password)) {
        $fail('password berubah padahal password lama salah');
    }

    [$status] = $call('PUT', '/api/profile', $token, ['current_password' => 'secret123', 'new_password' => 'rahasia456']);
    if ($status !== 200 || ! Hash::check('rahasia456', User::find($user->id)->password)) {
        $fail('ubah password gagal');
    }

    [$status] = $call('POST', '/api/settings', $token, ['theme_palette' => 'mint']);
    if ($status !== 403) {
        $fail('user biasa menulis settings seharusnya 403');
    }

    $user->is_admin = 1;
    $user->save();
    [$status] = $call('POST', '/api/settings', $token, ['theme_palette' => 'mint', 'theme_dark' => 'hijau']);
    if ($status !== 200) {
        $fail('admin gagal menyimpan tema');
    }
    [$status, $body] = $call('GET', '/api/settings', $token);
    if (($body['theme_palette'] ?? null) !== 'mint' || ($body['theme_dark'] ?? null) !== 'hijau') {
        $fail('GET settings tidak mengembalikan tema tersimpan');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($oldPalette === null) {
        Setting::whereKey('theme_palette')->delete();
    } else {
        Setting::set('theme_palette', $oldPalette);
    }
    if ($oldDark === null) {
        Setting::whereKey('theme_dark')->delete();
    } else {
        Setting::set('theme_dark', $oldDark);
    }
    if ($user) {
        User::whereKey($user->id)->delete();
    }
}

if ($error !== null) {
    fwrite(STDERR, "FAIL: $error\n");
    exit(1);
}

echo "OK: profil & proteksi settings\n";
