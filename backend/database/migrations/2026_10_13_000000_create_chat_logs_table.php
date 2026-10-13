<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_logs', function (Blueprint $table) {
            $table->id();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('prompt_tokens')->nullable();
            $table->unsignedBigInteger('completion_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->string('status')->default('ok')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
    }
};