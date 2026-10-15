<?php

// Self-check for App\Support\Eta — no framework required.
// Run: docker exec ai-chat-backend php tests/eta_check.php

require __DIR__.'/../app/Support/Eta.php';

use App\Support\Eta;

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
};

// Setengah jalan setelah 30 detik -> sisa 30 detik.
if (Eta::seconds(1000, 50, 1030) !== 30) {
    $fail('half done in 30s should leave ~30s');
}
// Seperempat jalan setelah 10 detik -> sisa 30 detik.
if (Eta::seconds(1000, 25, 1010) !== 30) {
    $fail('quarter done in 10s should leave ~30s');
}
// Selesai -> nol.
if (Eta::seconds(1000, 100, 1030) !== 0) {
    $fail('done should be 0');
}
// Belum mulai / belum bergerak -> null.
if (Eta::seconds(null, 50, 1030) !== null || Eta::seconds(1000, 0, 1030) !== null) {
    $fail('unknown progress should be null');
}

echo "OK: Eta formula\n";
