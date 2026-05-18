<?php

use App\Http\Controllers\BotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook', [BotWebhookController::class, 'handle']);
