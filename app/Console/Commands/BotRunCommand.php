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
        $this->info('Bot runner started. To\'xtatish uchun Ctrl+C bosing.');

        $lastActive = null;

        while (true) {
            $state          = $stateService->getState();
            $templates      = $templateService->all();
            $count          = count($templates);
            $activeGroupIds = $state['active_group_ids'] ?? [];
            $isActive       = (bool) $state['is_active'] && $count > 0 && !empty($activeGroupIds);
            $interval       = max(5, (int) ($state['interval'] ?? 30));

            if ($lastActive !== $isActive) {
                $lastActive = $isActive;
                $label = $isActive
                    ? "🟢 Bot faol — har {$interval}s da " . count($activeGroupIds) . " ta guruhga yuboradi"
                    : '🔴 Bot to\'xtatilgan — /start buyrug\'ini kuting';
                $this->line('[' . now()->format('H:i:s') . "] {$label}");
            }

            if ($isActive) {
                $index = ((int) $state['last_template_index']) % $count;
                $text  = $templates[$index];
                $num   = $index + 1;
                $sent  = 0;

                $adminId = (string) env('TELEGRAM_ADMIN_ID');

                foreach ($activeGroupIds as $groupId) {
                    try {
                        $sender->send((string) $groupId, $text);
                        $sent++;
                    } catch (\Throwable $e) {
                        $this->error('[' . now()->format('H:i:s') . "] ❌ {$groupId}: " . $e->getMessage());
                        Log::error('bot:run send error', ['group' => $groupId, 'message' => $e->getMessage()]);

                        try {
                            $sender->send($adminId, "⚠️ Guruhga yuborib bo'lmadi:\n<code>{$groupId}</code>\n❌ " . $e->getMessage());
                        } catch (\Throwable) {}
                    }
                }

                if ($sent > 0) {
                    $stateService->recordSend();
                    $this->line(
                        '[' . now()->format('H:i:s') . "] "
                        . "✅ Shablon #{$num} {$sent} ta guruhga yuborildi. "
                        . "Keyingisi {$interval}s dan so'ng."
                    );
                }

                sleep($interval);
            } else {
                sleep(5);
            }
        }
    }
}
