<?php

namespace App\Services\Telegram;

class TelegramClientFactory
{
    /**
     * Cache of sender instances keyed by bot token.
     *
     * The queue workers and bot:run are long-lived processes, and this factory
     * is bound as a singleton (see AppServiceProvider), so the same sender —
     * and therefore the same Guzzle/cURL connection to api.telegram.org — is
     * reused across jobs. That keeps the SOCKS5 + TLS tunnel warm, so each
     * Telegram call costs ~0.15s instead of paying the full ~0.6s handshake
     * every time a fresh client is built.
     *
     * @var array<string, TelegramSenderService>
     */
    private array $senders = [];

    public function make(string $token): TelegramSenderService
    {
        return $this->senders[$token] ??= new TelegramSenderService($token);
    }

    public function master(): TelegramSenderService
    {
        return $this->make(config('telegram.master_token'));
    }
}
