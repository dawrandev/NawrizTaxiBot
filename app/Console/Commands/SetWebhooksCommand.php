<?php

namespace App\Console\Commands;

use App\Models\DriverBot;
use App\Services\Telegram\TelegramClientFactory;
use Illuminate\Console\Command;

class SetWebhooksCommand extends Command
{
    protected $signature   = 'bot:set-webhooks';
    protected $description = 'Register Telegram webhooks for master bot and all driver bots';

    public function handle(TelegramClientFactory $factory): int
    {
        $baseUrl = rtrim(env('WEBHOOK_BASE_URL', config('app.url')), '/');
        $allowed = ['message', 'my_chat_member', 'callback_query'];

        // Master bot
        $masterUrl = "{$baseUrl}/api/webhook/master";

        try {
            $factory->master()->getApi()->setWebhook([
                'url'             => $masterUrl,
                'allowed_updates' => json_encode(['message', 'callback_query']),
            ]);
            $this->info("✅ Master bot: {$masterUrl}");
        } catch (\Throwable $e) {
            $this->error("❌ Master bot: {$e->getMessage()}");
        }

        // Driver bots
        $bots = DriverBot::all();

        if ($bots->isEmpty()) {
            $this->line('ℹ️  Hali driver bot yo\'q.');
            return 0;
        }

        foreach ($bots as $bot) {
            $url = "{$baseUrl}/api/webhook/driver/{$bot->id}";
            $api = $factory->make($bot->bot_token)->getApi();

            try {
                $api->setWebhook([
                    'url'             => $url,
                    'allowed_updates' => json_encode($allowed),
                ]);
                $this->info("✅ [{$bot->name}] @{$bot->bot_username}: {$url}");
            } catch (\Throwable $e) {
                $this->error("❌ [{$bot->name}] webhook: {$e->getMessage()}");
            }

            try {
                $api->deleteMyCommands();
                $this->line("   🔇 [{$bot->name}] команды удалены (бот невидим)");
            } catch (\Throwable $e) {
                $this->warn("   ⚠️ [{$bot->name}] deleteMyCommands: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
