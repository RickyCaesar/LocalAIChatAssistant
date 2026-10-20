<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Satu kali: pindah dari APP_TIMEZONE=UTC ke Asia/Makassar (WITA). Semua stempel
// waktu aplikasi sebelumnya ditulis sebagai wall-time UTC, jadi digeser +8 jam
// agar tetap menunjuk momen yang sama saat dibaca sebagai WITA.
// Tabel internal Laravel (jobs, cache, sessions, migrations) tidak disentuh.
return new class extends Migration
{
    private const TABLES = [
        'chat_logs', 'chat_sessions', 'events', 'media', 'notifications',
        'tasks', 'todos', 'transcriptions', 'users',
    ];

    public function up(): void
    {
        $this->shift(8);
    }

    // Rollback tidak menggeser balik: data yang masuk setelah migrasi ini
    // sudah WITA dan tidak bisa dibedakan.
    public function down(): void
    {
    }

    private function shift(int $hours): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getColumnListing($table) as $column) {
                if (! str_ends_with($column, '_at')) {
                    continue;
                }
                DB::table($table)
                    ->whereNotNull($column)
                    ->update([$column => DB::raw('datetime(`'.$column.'`, \''.($hours >= 0 ? '+' : '').$hours.' hours\')')]);
            }
        }
    }
};
