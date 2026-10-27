<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_plans', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->text('atasan_intervensi')->nullable()->after('slug');
            $table->text('rencana_kondisi')->nullable()->after('atasan_intervensi');
            $table->text('indikator_kinerja')->nullable()->after('rencana_kondisi');
            $table->string('satuan')->nullable()->after('indikator_kinerja');
            $table->string('target_tahunan')->nullable()->after('satuan');
            $table->string('penanggung_jawab_nama')->nullable()->after('target_tahunan');
            $table->string('penanggung_jawab_jabatan')->nullable()->after('penanggung_jawab_nama');
        });

        // shortcut: reset seed to full e-SAKIP structure (dev only)
        DB::table('performance_plans')->truncate();
        $now = now();
        DB::table('performance_plans')->insert([
            [
                'user_id' => null,
                'name' => 'Anggaran Kas',
                'slug' => 'anggaran-kas',
                'atasan_intervensi' => 'Meningkatnya Perencanaan Kinerja dan Penganggaran yang Selaras dan Tepat Waktu',
                'rencana_kondisi' => 'Anggaran Kas SKPD sesuai ketentuan perencanaan dan penganggaran daerah.',
                'indikator_kinerja' => 'Jumlah Dokumen Anggaran Kas SKPD',
                'satuan' => 'Dokumen',
                'target_tahunan' => null,
                'penanggung_jawab_nama' => null,
                'penanggung_jawab_jabatan' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'name' => 'Lapkin',
                'slug' => 'lapkin',
                'atasan_intervensi' => 'Meningkatnya Perencanaan Kinerja dan Penganggaran yang Selaras dan Tepat Waktu',
                'rencana_kondisi' => 'Tersusunnya Laporan Kinerja SKPD secara berkala',
                'indikator_kinerja' => 'Rata-Rata Capaian Sasaran / Jumlah Laporan Kinerja SKPD',
                'satuan' => 'Laporan',
                'target_tahunan' => null,
                'penanggung_jawab_nama' => null,
                'penanggung_jawab_jabatan' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'name' => 'Kemiskinan/Realisasi',
                'slug' => 'kemiskinan-realisasi',
                'atasan_intervensi' => 'Meningkatnya Perencanaan Kinerja dan Penganggaran yang Selaras dan Tepat Waktu',
                'rencana_kondisi' => 'Laporan Pengentasan Kemiskinan sektor pendidikan dan Laporan Realisasi Keuangan dan Fisik',
                'indikator_kinerja' => 'Jumlah Laporan Pengentasan Kemiskinan sektor pendidikan & Realisasi Fisik SKPD',
                'satuan' => 'Laporan',
                'target_tahunan' => null,
                'penanggung_jawab_nama' => null,
                'penanggung_jawab_jabatan' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'name' => 'DSSD',
                'slug' => 'dssd',
                'atasan_intervensi' => 'Meningkatnya Perencanaan Kinerja dan Penganggaran yang Selaras dan Tepat Waktu',
                'rencana_kondisi' => 'Terinputnya Data Statistik Sektoral Daerah (DSSD) Perangkat Daerah',
                'indikator_kinerja' => 'Jumlah Data Statistik Sektoral Daerah',
                'satuan' => 'Data',
                'target_tahunan' => null,
                'penanggung_jawab_nama' => null,
                'penanggung_jawab_jabatan' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'name' => 'Geospasial',
                'slug' => 'geospasial',
                'atasan_intervensi' => 'Meningkatnya Perencanaan Kinerja dan Penganggaran yang Selaras dan Tepat Waktu',
                'rencana_kondisi' => 'Terolahnya data geospasial pendidikan lingkup Provinsi Kalimantan Timur',
                'indikator_kinerja' => 'Jumlah Dokumen Laporan Geospasial lingkup Disdikbud Kaltim',
                'satuan' => 'Dokumen',
                'target_tahunan' => null,
                'penanggung_jawab_nama' => null,
                'penanggung_jawab_jabatan' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('performance_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['atasan_intervensi', 'rencana_kondisi', 'indikator_kinerja', 'satuan', 'target_tahunan', 'penanggung_jawab_nama', 'penanggung_jawab_jabatan']);
        });
    }
};
