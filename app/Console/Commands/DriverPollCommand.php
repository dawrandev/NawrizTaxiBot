<?php

namespace App\Console\Commands;

use App\Http\Controllers\DriverBotWebhookController;
use App\Models\DriverBot;
use App\Services\Telegram\TelegramClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-polls every DRIVER bot via getUpdates THROUGH the SOCKS5 proxy.
 *
 * Same reasoning as master:poll — Telegram's webhook delivery to this UZ host
 * times out, so we pull updates ourselves. Driver bots are few (usually one),
 * so each gets a dedicated long-poll; with a single bot the poll blocks for
 * 25s while idle (≈1 request / 25s). The active-bot list is re-read every
 * cycle, so bots added/removed via the master panel are picked up live.
 */
class DriverPollCommand extends Command
{
    protected $signature   = 'driver:poll';
    protected $description = 'Long-poll all driver bots for updates (proxy, no webhook)';

    public function handle(TelegramClientFactory $factory): int
    {
        $controller = app(DriverBotWebhookController::class);

        $this->info('Driver polling запущен (proxy, без вебхука). Ctrl+C для остановки.');

        $offsets        = [];   // bot id => next update offset
        $webhookCleared = [];   // bot id => true once its webhook is removed

        while (true) {
            $bots = DriverBot::all();

            if ($bots->isEmpty()) {
                sleep(5);
                continue;
            }

            // One bot can long-poll for 25s; with several we keep each poll
            // short so we rotate through them without starving any.
            $timeout = $bots->count() === 1 ? 25 : 1;

            foreach ($bots as $bot) {
                $api = $factory->make($bot->bot_token)->getApi();

                if (empty($webhookCleared[$bot->id])) {
                    try {
                        $api->deleteWebhook();
                        $webhookCleared[$bot->id] = true;
                    } catch (\Throwable $e) {
                        Log::error('driver:poll deleteWebhook error', [
                            'bot'   => $bot->name,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }

                try {
                    $updates = $api->getUpdates([
                        'offset'          => $offsets[$bot->id] ?? 0,
                        'timeout'         => $timeout,
                        'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
                    ]);
                } catch (\Throwable $e) {
                    Log::error('driver:poll getUpdates error', [
                        'bot'   => $bot->name,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                foreach ($updates as $update) {
                    $arr               = $update->toArray();
                    $updateId          = (int) ($arr['update_id'] ?? 0);
                    $offsets[$bot->id] = $updateId + 1; // ack

                    try {
                        $controller->process($arr, $bot);
                    } catch (\Throwable $e) {
                        Log::error('driver:poll process error', [
                            'bot'       => $bot->name,
                            'update_id' => $updateId,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }
}
