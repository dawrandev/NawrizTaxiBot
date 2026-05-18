<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;

class TelegramSenderService
{
    private Api $telegram;

    public function __construct(private readonly BotStateService $stateService)
    {
        $guzzle     = new GuzzleClient(['timeout' => 10.0]);
        $httpClient = new GuzzleHttpClient($guzzle);

        $this->telegram = new Api(
            config('telegram.bots.mybot.token'),
            false,
            $httpClient
        );
    }

    /**
     * Send a message to the group chat saved in bot state.
     *
     * @throws \RuntimeException When the bot has not been added to any group yet.
     */
    public function sendToGroup(string $text): void
    {
        $groupChatId = $this->stateService->getGroupChatId();

        if (!$groupChatId) {
            throw new \RuntimeException('Bot has not been added to any group yet.');
        }

        $this->telegram->sendMessage([
            'chat_id'    => $groupChatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Send a plain HTML message to any chat, optionally with an inline keyboard.
     */
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

        $this->telegram->sendMessage($params);
    }

    /**
     * Edit an existing message's text and inline keyboard in-place.
     */
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

        $this->telegram->editMessageText($params);
    }

    /**
     * Acknowledge a callback query to dismiss the loading indicator on the button.
     */
    public function answerCallback(string $callbackQueryId): void
    {
        $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
    }

    /**
     * Expose the underlying Telegram Bot API instance.
     */
    public function getApi(): Api
    {
        return $this->telegram;
    }
}
