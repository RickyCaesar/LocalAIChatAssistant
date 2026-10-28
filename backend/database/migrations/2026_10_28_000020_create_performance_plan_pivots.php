<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_plan_todo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            $table->foreignId('todo_id')->constrained('todos')->cascadeOnDelete();
            $table->unique(['performance_plan_id', 'todo_id']);
        });

        Schema::create('performance_plan_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unique(['performance_plan_id', 'event_id']);
        });

        DB::table('todos')->whereNotNull('performance_plan_id')->orderBy('id')->chunkById(200, function ($rows) {
            DB::table('performance_plan_todo')->insertOrIgnore($rows->map(fn ($row) => [
                'performance_plan_id' => $row->performance_plan_id,
                'todo_id' => $row->id,
            ])->all());
        });

        DB::table('events')->whereNotNull('performance_plan_id')->orderBy('id')->chunkById(200, function ($rows) {
            DB::table('performance_plan_event')->insertOrIgnore($rows->map(fn ($row) => [
                'performance_plan_id' => $row->performance_plan_id,
                'event_id' => $row->id,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_plan_event');
        Schema::dropIfExists('performance_plan_todo');
    }
};
