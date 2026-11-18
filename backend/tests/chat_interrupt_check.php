<?php

// Self-check mekanisme Stop: read_timeout membangunkan loop, lalu menutup body
// memutus koneksi upstream agar gateway membatalkan agent yang berjalan.
// Run: docker exec ai-chat-backend php tests/chat_interrupt_check.php

require __DIR__.'/../vendor/autoload.php';

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
};

$dir = sys_get_temp_dir().'/chat-interrupt-'.bin2hex(random_bytes(3));
mkdir($dir);
$log = $dir.'/iterations.log';
$shutdown = $dir.'/shutdown.log';
$router = $dir.'/router.php';
$port = 18000 + random_int(0, 999);

file_put_contents($router, <<<'PHP'
<?php
register_shutdown_function(function () {
    file_put_contents(getenv('SHUTDOWN_LOG'), 'closed');
});
header('Content-Type: text/event-stream');
echo ': ';
flush();
usleep(500000);
for ($i = 0; $i < 100; $i++) {
    echo "ping\n\n";
    flush();
    file_put_contents(getenv('ITER_LOG'), "i=$i\n", FILE_APPEND);
    usleep(100000);
}
PHP);

$env = ['ITER_LOG' => $log, 'SHUTDOWN_LOG' => $shutdown] + getenv();
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
    [1 => ['file', $dir.'/server.log', 'a'], 2 => ['file', $dir.'/server.log', 'a']],
    $pipes,
    null,
    $env
);
if (! is_resource($proc)) {
    $fail('server uji gagal dijalankan');
}
usleep(300000);

try {
    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', 'http://127.0.0.1:'.$port.'/', [
        'stream' => true,
        'read_timeout' => 0.2,
        'timeout' => 5,
    ]);
    $body = $response->getBody();
    $body->read(8192); // header sudah terbuka; upstream kini diam

    try {
        $body->read(8192);
        $fail('read seharusnya timeout saat upstream diam');
    } catch (\RuntimeException) {
        if (! ($body->getMetadata('timed_out') ?? false)) {
            $fail('exception read bukan timeout');
        }
    }

    $body->close();
    usleep(900000);

    // Koneksi putus: server upstream berhenti menulis dan request-nya selesai.
    $count = substr_count((string) @file_get_contents($log), "\n");
    if ($count > 1 || ! is_file($shutdown)) {
        $fail("upstream tidak mendeteksi koneksi diputus (iterasi=$count)");
    }
} finally {
    proc_terminate($proc);
    proc_close($proc);
    @unlink($log);
    @unlink($shutdown);
    @unlink($router);
    @unlink($dir.'/server.log');
    @rmdir($dir);
}

echo "OK: read timeout terdeteksi dan disconnect memutus upstream.\n";
