<?php

namespace App\Services\Telegram;

use GuzzleHttp\Client as GuzzleClient;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;

class TelegramSenderService
{
    private Api $api;

    public function __construct(string $token)
    {
        // Keep the underlying TCP/TLS connection alive between requests. This
        // sender is reused across jobs (singleton factory), so cURL keeps the
        // warm SOCKS5+TLS tunnel in its pool and skips the ~0.6s handshake on
        // every call but the first.
        $curl = [
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE  => 30,
            CURLOPT_TCP_KEEPINTVL => 15,
        ];

        // Proxy: prefer shell env (https_proxy), fall back to .env TELEGRAM_PROXY.
        // The server is in a region where api.telegram.org is heavily throttled
        // (~20s direct), so the local SOCKS5 tunnel (127.0.0.1:1080) is required.
        $proxy = getenv('https_proxy') ?: getenv('HTTPS_PROXY') ?: config('telegram.proxy');
        if ($proxy) {
            $parsed = parse_url($proxy);
            $curl[CURLOPT_PROXY] = ($parsed['host'] ?? '127.0.0.1') . ':' . ($parsed['port'] ?? 1080);
            // SOCKS5_HOSTNAME = resolve DNS through the proxy. Plain SOCKS5
            // resolves locally, which fails where DNS itself is blocked.
            $curl[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
        }

        // 'connection' keep-alive header + a persistent Guzzle client (one per
        // token, cached by the factory) let cURL reuse the same connection.
        $guzzle = new GuzzleClient([
            'timeout'     => 30.0,
            'curl'        => $curl,
            'headers'     => ['Connection' => 'keep-alive'],
        ]);
        $this->api = new Api($token, false, new GuzzleHttpClient($guzzle));
    }

    public function send(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        $this->api->sendMessage($params);
    }

    public function editMessage(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        $this->api->editMessageText($params);
    }

    public function answerCallback(string $callbackQueryId, string $text = ''): void
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== '') {
            $params['text'] = $text;
        }
        $this->api->answerCallbackQuery($params);
    }

    public function leaveChat(string $chatId): void
    {
        $this->api->leaveChat(['chat_id' => $chatId]);
    }

    public function getApi(): Api
    {
        return $this->api;
    }
}
