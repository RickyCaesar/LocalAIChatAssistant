<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\Task;

// Notifikasi in-app; frontend mempoll-nya (lihat App.jsx) lalu memunculkan
// toast + browser push. Bukan Laravel Notifications karena hanya butuh satu
// tabel sederhana + satu channel (tidak ada mail/queue channel).
class NotificationService
{
    // Best-effort: kegagalan menulis notifikasi tidak boleh menggagalkan
    // pekerjaan yang sudah selesai.
    public static function send(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void
    {
        try {
            Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'link' => $link,
            ]);
        } catch (\Throwable) {
            /* abaikan */
        }
    }

    public static function virusDetected(int $userId, string $filename): void
    {
        self::send(
            $userId,
            'File virus terdeteksi',
            'File "'.basename($filename).'" terdeteksi berbahaya dan diblokir.',
            'error',
        );
    }

    // Satu tempat untuk semua task antrean (RunTask): sukses & gagal.
    public static function taskFinished(Task $task): void
    {
        $labels = [
            'convert' => 'Konversi MP3',
            'transcribe' => 'Transkripsi',
            'notulensi' => 'Notulensi',
            'pptx' => 'Presentasi',
        ];
        $label = $labels[$task->type] ?? ucfirst($task->type);
        $done = $task->status === 'done';

        self::send(
            (int) $task->user_id,
            $done ? $label.' selesai' : $label.' gagal',
            $done ? (string) ($task->title ?: $label) : (string) ($task->error ?: 'Terjadi kesalahan.'),
            $done ? 'success' : 'error',
            $done ? (($task->result['url'] ?? null) ? 'gallery' : 'queue') : 'queue',
        );
    }
}
