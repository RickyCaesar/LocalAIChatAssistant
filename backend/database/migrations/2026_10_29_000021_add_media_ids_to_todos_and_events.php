<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->json('media_ids')->nullable()->after('media_id');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->json('media_ids')->nullable()->after('media_id');
        });

        DB::table('todos')->whereNotNull('media_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('todos')->where('id', $row->id)->update(['media_ids' => json_encode([(int) $row->media_id])]);
            }
        });
        DB::table('events')->whereNotNull('media_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('events')->where('id', $row->id)->update(['media_ids' => json_encode([(int) $row->media_id])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn('media_ids');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('media_ids');
        });
    }
};
