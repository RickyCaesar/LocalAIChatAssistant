<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->decimal('durasi_jam', 5, 2)->nullable();
            $table->text('tindak_lanjut')->nullable();
            $table->foreignId('performance_plan_id')->nullable()->constrained('performance_plans')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('todo_id')->nullable()->constrained('todos')->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('tindak_lanjut')->nullable();
            $table->foreignId('performance_plan_id')->nullable()->constrained('performance_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('media_id');
            $table->dropColumn('durasi_jam');
            $table->dropColumn('tindak_lanjut');
            $table->dropConstrainedForeignId('performance_plan_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('todo_id');
            $table->dropConstrainedForeignId('media_id');
            $table->dropColumn('tindak_lanjut');
            $table->dropConstrainedForeignId('performance_plan_id');
        });
    }
};
