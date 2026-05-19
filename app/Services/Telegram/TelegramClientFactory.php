<?php

namespace App\Services\Telegram;

use GuzzleHttp\Client as GuzzleClient;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;

class TelegramClientFactory
{
    public function make(string $token): TelegramSenderService
    {
        return new TelegramSenderService($token);
    }

    public function master(): TelegramSenderService
    {
        return $this->make(config('telegram.master_token'));
    }
}
