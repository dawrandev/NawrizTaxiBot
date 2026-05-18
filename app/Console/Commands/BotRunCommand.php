<?php

namespace App\Console\Commands;

use App\Services\BotStateService;
use App\Services\TemplateService;
use App\Services\TelegramSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BotRunCommand extends Command
{
    protected $signature   = 'bot:run';
    protected $description = 'Start the Telegram bot message sender (runs continuously)';

    public function handle(
        BotStateService $stateService,
        TemplateService $templateService,
        TelegramSenderService $sender
    ): int {
        $this->info('Bot runner started.');

        while (true) {
            $state     = $stateService->getState();
            $interval  = max(5, (int) ($state['interval'] ?? 30));
            $templates = $templateService->all();
            $count     = count($templates);

            if ($state['is_active'] && $count > 0) {
                $index     = $count > 0 ? ((int) $state['last_template_index']) % $count : 0;
                $nextIndex = ($index + 1) % $count;

                try {
                    $sender->sendToGroup($templates[$index]);
                    $stateService->updateAfterSend($nextIndex);
                    $this->line('[' . now()->format('H:i:s') . '] Sent template #' . ($index + 1));
                } catch (\Throwable $e) {
                    $this->error('[' . now()->format('H:i:s') . '] Send failed: ' . $e->getMessage());
                    Log::error('bot:run send error', ['message' => $e->getMessage()]);
                }
            }

            sleep($interval);
        }
    }
}
