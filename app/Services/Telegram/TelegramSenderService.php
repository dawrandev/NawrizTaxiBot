<?php

namespace App\Services\Telegram;

use Telegram\Bot\Api;

class TelegramSenderService
{
    private Api $api;

    public function __construct(string $token)
    {
        $this->api = new Api($token);
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

    public function getApi(): Api
    {
        return $this->api;
    }
}
