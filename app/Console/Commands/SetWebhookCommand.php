<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;

class SetWebhookCommand extends Command
{
    protected $signature   = 'bot:set-webhook';
    protected $description = 'Register the webhook URL with Telegram';

    public function handle(): int
    {
        $token      = config('telegram.bots.mybot.token');
        $webhookUrl = config('telegram.bots.mybot.webhook_url');

        if (empty($token)) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return Command::FAILURE;
        }

        if (empty($webhookUrl)) {
            $this->error('TELEGRAM_WEBHOOK_URL is not configured.');

            return Command::FAILURE;
        }

        $telegram = new Api($token);
        $result   = $telegram->setWebhook([
            'url'             => $webhookUrl,
            'allowed_updates' => json_encode(['message', 'my_chat_member', 'callback_query']),
        ]);

        if ($result) {
            $this->info("Webhook successfully registered: {$webhookUrl}");
            $this->line('Listening for: message, my_chat_member, callback_query');

            return Command::SUCCESS;
        }

        $this->error('Failed to set webhook. Verify your token and webhook URL.');

        return Command::FAILURE;
    }
}
