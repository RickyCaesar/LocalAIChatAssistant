<?php

namespace App\Support;

class Eta
{
    // Sisa waktu (detik) = laju sejak mulai * pekerjaan tersisa.
    // elapsed / progress * (100 - progress). null bila belum mulai / belum bergerak.
    public static function seconds(?int $startedAt, int $progress, ?int $now = null): ?int
    {
        if ($startedAt === null || $progress <= 0) {
            return null;
        }
        $now ??= time();
        $elapsed = max(0, $now - $startedAt);
        $progress = min(100, $progress);

        return (int) round($elapsed * (100 - $progress) / $progress);
    }
}
