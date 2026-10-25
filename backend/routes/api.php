<?php

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\TTSController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureBearerToken;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(EnsureBearerToken::class)->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::put('/sessions/{id}', [SessionController::class, 'update']);
    Route::delete('/sessions/{id}', [SessionController::class, 'destroy']);

    Route::post('/chat', [ChatController::class, 'chat']);

    Route::get('/media', [MediaController::class, 'index']);
    Route::post('/media', [MediaController::class, 'store']);
    Route::get('/media/{id}', [MediaController::class, 'show']);
    Route::post('/media/{id}/pdf', [MediaController::class, 'toPdf']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);

    Route::post('/presentations', [QueueController::class, 'storePptx']);
    Route::post('/presentations/draft', [AIController::class, 'presentationDraft']);

    Route::get('/queue', [QueueController::class, 'index']);
    Route::get('/queue/{id}', [QueueController::class, 'show']);
    Route::post('/queue/transcribe', [QueueController::class, 'storeTranscribe']);
    Route::post('/queue/convert', [QueueController::class, 'storeConvert']);
    Route::post('/queue/notulensi', [QueueController::class, 'storeNotulensi']);
    Route::delete('/queue/{id}', [QueueController::class, 'destroy']);

    Route::get('/tts/voices', [TTSController::class, 'voices']);
    Route::post('/tts', [TTSController::class, 'speak']);
    Route::post('/stt', [TTSController::class, 'stt']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);

    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::put('/todos/{id}', [TodoController::class, 'update']);
    Route::delete('/todos/{id}', [TodoController::class, 'destroy']);

    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']);

    Route::middleware('admin')->group(function () {
        Route::get('/analytics', [AIController::class, 'analytics']);
        Route::get('/skills', [SkillController::class, 'index']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::post('/settings', [AIController::class, 'updateSettings']);
    });
    // GET tetap terbuka: semua user membacanya untuk preferensi TTS + tema global.
    Route::get('/settings', [AIController::class, 'settings']);
});

Route::get('/health', static function () {
    return response()->json(['status' => 'ok']);
});