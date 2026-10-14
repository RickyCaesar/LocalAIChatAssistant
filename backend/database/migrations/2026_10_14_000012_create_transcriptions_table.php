<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | processing | done | error
            $table->string('filename');
            $table->string('path'); // relative to storage/app
            $table->text('text')->nullable();
            $table->string('language')->nullable();
            $table->float('duration')->nullable();
            $table->string('model')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
