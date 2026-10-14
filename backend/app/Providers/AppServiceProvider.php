<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The web server and the queue worker share one SQLite file mounted as
        // a *single file* (not its directory), so WAL would split the DB: each
        // container gets its own -wal/-shm sidecar and never sees the other's
        // writes. Stay in rollback-journal mode (shared file is authoritative)
        // and just wait on locks. ponytail: for heavier concurrency, move the
        // DB into a shared directory (or Postgres) so WAL can be used.
        config([
            'database.connections.sqlite.journal_mode' => 'delete',
            'database.connections.sqlite.busy_timeout' => 5000,
        ]);
    }

    public function boot(): void
    {
        //
    }
}
