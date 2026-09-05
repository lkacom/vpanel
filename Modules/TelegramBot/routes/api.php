<?php

use Illuminate\Support\Facades\Route;
use Modules\TelegramBot\Http\Controllers\TelegramBotController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('telegrambots', TelegramBotController::class)->names('telegrambot');
});
