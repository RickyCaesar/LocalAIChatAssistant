<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $now = now();
        DB::table('performance_plans')->insert([
            ['name' => 'Anggaran Kas', 'slug' => 'anggaran-kas', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Lapkin', 'slug' => 'lapkin', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Kemiskinan/Realisasi', 'slug' => 'kemiskinan-realisasi', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'DSSD', 'slug' => 'dssd', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Geospasial', 'slug' => 'geospasial', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_plans');
    }
};
