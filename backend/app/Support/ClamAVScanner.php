<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class ClamAVScanner
{
    private const CHUNK_SIZE = 65536;

    // shortcut: disable/unavailable clamd skips scanning; fail closed here if
    // antivirus scanning must become mandatory.

    public static function scanFile(string $filePath): bool
    {
        if (! filter_var(env('CLAMAV_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $host = trim((string) env('CLAMAV_HOST', 'clamav'));
        $port = (int) env('CLAMAV_PORT', 3310);
        if ($host === '' || $port < 1) {
            Log::warning('ClamAV skipped: invalid host or port.');
            return true;
        }

        $file = @fopen($filePath, 'rb');
        if (! $file) {
            Log::warning('ClamAV skipped: file not readable.', ['file' => basename($filePath)]);
            return true;
        }

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 3);
        if (! $socket) {
            fclose($file);
            Log::warning('ClamAV unavailable; continuing without scan.', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
            ]);
            return true;
        }

        stream_set_timeout($socket, 30);

        try {
            fwrite($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));

            $response = (string) stream_get_line($socket, 8192, "\0");
        } finally {
            fclose($file);
            fclose($socket);
        }

        if (str_contains($response, 'FOUND')) {
            return false;
        }
        if (! str_contains($response, 'OK')) {
            Log::warning('ClamAV scan failed; continuing without result.', [
                'file' => basename($filePath),
                'response' => trim($response),
            ]);
        }

        return true;
    }
}
