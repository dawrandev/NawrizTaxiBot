<?php

namespace App\Console\Commands;

use App\Http\Controllers\MasterBotWebhookController;
use App\Services\Telegram\TelegramClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-polls the MASTER bot via getUpdates THROUGH the SOCKS5 proxy.
 *
 * Webhooks (Telegram -> server) are unreliable on this UZ host: Telegram
 * frequently fails to reach the public endpoint ("Connection timed out"),
 * adding 15s+ before an admin action is processed. Outbound through the proxy
 * is fast and reliable (~0.15s warm), so we pull updates ourselves instead of
 * waiting for Telegram to push them. No webhook = no inbound timeout.
 */
class MasterPollCommand extends Command
{
    protected $signature   = 'master:poll';
    protected $description = 'Long-poll the master bot for updates (proxy, no webhook)';

    public function handle(TelegramClientFactory $factory): int
    {
        $api        = $factory->master()->getApi();
        $controller = app(MasterBotWebhookController::class);

        // getUpdates and a webhook are mutually exclusive — drop the webhook.
        // (deleteWebhook keeps pending updates by default, so nothing is lost.)
        try {
            $api->deleteWebhook();
        } catch (\Throwable $e) {
            $this->error("deleteWebhook: {$e->getMessage()}");
        }

        $this->info('Master polling запущен (proxy, без вебхука). Ctrl+C для остановки.');

        $offset = 0;

        while (true) {
            try {
                // timeout=25 < Guzzle's 30s client timeout. Long-poll: the call
                // blocks until an update arrives or 25s pass, so an idle bot
                // makes ~1 request / 25s — very light.
                $updates = $api->getUpdates([
                    'offset'          => $offset,
                    'timeout'         => 25,
                    'allowed_updates' => ['message', 'callback_query'],
                ]);
            } catch (\Throwable $e) {
                Log::error('master:poll getUpdates error', ['error' => $e->getMessage()]);
                sleep(1);
                continue;
            }

            $batch = count($updates);

            foreach ($updates as $update) {
                $arr      = $update->toArray();
                $updateId = (int) ($arr['update_id'] ?? 0);
                $offset   = $updateId + 1; // ack: never receive this update again

                $t0 = microtime(true);
                try {
                    $controller->process($arr);
                } catch (\Throwable $e) {
                    Log::error('master:poll process error', [
                        'update_id' => $updateId,
                        'error'     => $e->getMessage(),
                    ]);
                }
                $this->timing('master', $updateId, $t0, $batch);
            }
        }
    }

    /**
     * Append one timing line to storage/logs/poll.log so we can see, per tap,
     * how long process() took and how many updates were waiting in the batch
     * (a growing batch = rapid taps piling up behind serial processing).
     */
    private function timing(string $tag, int $updateId, float $t0, int $batch): void
    {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        @file_put_contents(
            storage_path('logs/poll.log'),
            sprintf("%s [%s] update %d: %dms (batch %d)\n", date('H:i:s'), $tag, $updateId, $ms, $batch),
            FILE_APPEND
        );
    }
}
