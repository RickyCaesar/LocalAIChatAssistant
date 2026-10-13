<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static function () {
    return response()->json([
        'name' => 'Local AI Chat Assistant API',
        'status' => 'ok',
    ]);
});