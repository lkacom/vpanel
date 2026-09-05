<?php

use Illuminate\Support\Facades\Route;
use Modules\TelegramBot\Http\Controllers\WebhookController;


Route::post('/webhooks/telegram', [WebhookController::class, 'handle'])->name('telegram.webhook');
