<?php

namespace App\Console\Commands;

use App\Models\DriverBot;
use App\Services\Telegram\TelegramClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BotRunCommand extends Command
{
    protected $signature   = 'bot:run';
    protected $description = 'Run the multi-bot message sender (runs continuously)';

    public function handle(TelegramClientFactory $factory): int
    {
        $this->info('Бот запущен. Для остановки нажмите Ctrl+C.');

        while (true) {
            $bots = DriverBot::where('is_active', true)
                ->with(['currentTemplate', 'activeGroups'])
                ->get();

            if ($bots->isEmpty()) {
                sleep(30);
                continue;
            }

            $minSleep = 5;

            foreach ($bots as $bot) {
                $interval = max(5, $bot->interval);
                $lastSent = $bot->last_sent_at;
                $elapsed  = $lastSent ? (int) $lastSent->diffInSeconds(now()) : $interval;

                if ($elapsed < $interval) {
                    $remaining = $interval - $elapsed;
                    $this->line('[' . now()->format('H:i:s') . "] [{$bot->name}] ⏳ {$elapsed}s/{$interval}s — {$remaining}s qoldi");
                    if ($remaining < $minSleep) {
                        $minSleep = $remaining;
                    }
                    continue;
                }

                $template = $bot->currentTemplate;
                $groups   = $bot->activeGroups;

                if (!$template) {
                    $this->warn('[' . now()->format('H:i:s') . "] [{$bot->name}] ⚠️ Шаблон не найден (current_template_id={$bot->current_template_id})");
                    continue;
                }

                if ($groups->isEmpty()) {
                    $this->warn('[' . now()->format('H:i:s') . "] [{$bot->name}] ⚠️ Нет активных групп");
                    continue;
                }

                $this->line('[' . now()->format('H:i:s') . "] [{$bot->name}] 📤 Отправка в {$groups->count()} групп...");

                $sender = $factory->make($bot->bot_token);
                $sent   = 0;

                foreach ($groups as $group) {
                    try {
                        $sender->send($group->group_chat_id, $template->body);
                        $sent++;
                        $this->line('[' . now()->format('H:i:s') . "] [{$bot->name}] ✅ → {$group->displayTitle()}");
                    } catch (\Throwable $e) {
                        $msg = $e->getMessage();
                        $this->error('[' . now()->format('H:i:s') . "] [{$bot->name}] ❌ {$group->displayTitle()}: {$msg}");
                        Log::error('bot:run send error', [
                            'bot'   => $bot->name,
                            'group' => $group->group_chat_id,
                            'error' => $msg,
                        ]);
                        try {
                            $sender->send($bot->chat_id, "⚠️ Не удалось отправить в группу: <b>{$group->displayTitle()}</b>\n❌ {$msg}");
                        } catch (\Throwable) {}
                    }
                }

                if ($sent > 0) {
                    $bot->update(['last_sent_at' => now()]);
                    $this->info('[' . now()->format('H:i:s') . "] [{$bot->name}] ✅ {$sent}/{$groups->count()} групп. След.: {$interval}с");
                }
            }

            sleep(max(1, $minSleep));
        }
    }
}
