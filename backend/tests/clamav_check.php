<?php

// Self-check ClamAV stream scanner (clean + EICAR). Needs the clamav service.
// Run: docker compose cp backend/tests/clamav_check.php backend:/app/tests/clamav_check.php
//      docker compose exec backend php tests/clamav_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Support\ClamAVScanner;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$clean = tempnam(sys_get_temp_dir(), 'clamav-clean');
$infected = tempnam(sys_get_temp_dir(), 'clamav-eicar');
$eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

try {
    file_put_contents($clean, 'safe content');
    file_put_contents($infected, $eicar);

    if (! ClamAVScanner::scanFile($clean)) {
        throw new RuntimeException('clean file was rejected');
    }
    if (ClamAVScanner::scanFile($infected)) {
        throw new RuntimeException('EICAR test file was not detected');
    }

    echo "OK: ClamAV scan clean + infected\n";
} finally {
    @unlink($clean);
    @unlink($infected);
}
