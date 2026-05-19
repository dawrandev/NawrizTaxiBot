<?php

use App\Http\Controllers\DriverBotWebhookController;
use App\Http\Controllers\MasterBotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/master', [MasterBotWebhookController::class, 'handle']);
Route::post('/webhook/driver/{driverBot}', [DriverBotWebhookController::class, 'handle']);
