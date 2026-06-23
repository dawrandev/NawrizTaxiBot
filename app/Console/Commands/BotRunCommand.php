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

    /**
     * Pause between sends to DIFFERENT groups within one cycle (microseconds).
     *
     * Each group is a distinct chat, so Telegram's per-chat limit (~1 msg/s)
     * doesn't apply here — only the global ~30 msg/s ceiling does. 250ms (4/s)
     * stays well under that while broadcasting ~4x faster than the old 1s.
     * If a group ever floods, the "retry after" handler below backs it off.
     */
    private const SEND_PACING_US = 250_000;

    public function handle(TelegramClientFactory $factory): int
    {
        $this->info('Бот запущен. Для остановки нажмите Ctrl+C.');

        // In-memory state across cycles (bot:run is a persistent loop)
        $floodUntil = []; // "{botId}:{chatId}" => unix ts to skip until (Telegram flood)
        $notified   = []; // "{botId}:{chatId}" => true once driver was told about a failure

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
                    // Mid-cycle stop check: if driver pressed Stop, abandon this cycle immediately
                    if (!(bool) DriverBot::where('id', $bot->id)->value('is_active')) {
                        $this->line('[' . now()->format('H:i:s') . "] [{$bot->name}] 🛑 Остановлено водителем — прерываем цикл");
                        break;
                    }

                    $key = $bot->id . ':' . $group->group_chat_id;

                    // Skip groups still inside a Telegram flood (retry after) window
                    if (isset($floodUntil[$key]) && time() < $floodUntil[$key]) {
                        $left = $floodUntil[$key] - time();
                        $this->line('[' . now()->format('H:i:s') . "] [{$bot->name}] ⛔ {$group->displayTitle()} — flood, {$left}s qoldi");
                        continue;
                    }

                    try {
                        $sender->send($group->group_chat_id, $template->body);
                        $sent++;
                        unset($notified[$key]); // recovered — allow future failures to notify again
                        $this->line('[' . now()->format('H:i:s') . "] [{$bot->name}] ✅ → {$group->displayTitle()}");
                        // Pacing between different groups (see SEND_PACING_US).
                        usleep(self::SEND_PACING_US);
                    } catch (\Throwable $e) {
                        $msg = $e->getMessage();
                        $this->error('[' . now()->format('H:i:s') . "] [{$bot->name}] ❌ {$group->displayTitle()}: {$msg}");
                        Log::error('bot:run send error', [
                            'bot'   => $bot->name,
                            'group' => $group->group_chat_id,
                            'error' => $msg,
                        ]);

                        // Honor "retry after N" — skip this group for N seconds
                        if (preg_match('/retry after (\d+)/i', $msg, $m)) {
                            $floodUntil[$key] = time() + (int) $m[1];
                        }

                        // Notify the driver only once per failure episode (not every cycle)
                        if (!isset($notified[$key])) {
                            $notified[$key] = true;
                            try {
                                $human = $this->humanizeError($msg);
                                $sender->send($bot->chat_id, "⚠️ <b>Не удалось отправить в группу:</b>\n📍 <b>{$group->displayTitle()}</b>\n\n{$human}");
                            } catch (\Throwable) {}
                        }
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

    private function humanizeError(string $msg): string
    {
        $lower = mb_strtolower($msg);

        if (str_contains($lower, 'too many requests')) {
            preg_match('/retry after (\d+)/i', $msg, $m);
            $wait = isset($m[1]) ? " через {$m[1]} сек" : '';
            return implode("\n", [
                "🚦 <b>Слишком частая отправка</b>",
                "Telegram временно ограничил бота из-за спама. Повтор{$wait}.",
                '',
                "✅ <b>Решение:</b> увеличьте интервал отправки (рекомендуется 60 секунд и больше).",
            ]);
        }

        if (str_contains($lower, 'not enough rights')) {
            return implode("\n", [
                "🔒 <b>Нет прав на отправку</b>",
                "Бот не может писать сообщения в этой группе.",
                '',
                "✅ <b>Решение:</b> сделайте бота администратором группы.",
            ]);
        }

        if (str_contains($lower, 'kicked') || str_contains($lower, 'not a member')) {
            return implode("\n", [
                "🚪 <b>Бот удалён из группы</b>",
                '',
                "✅ <b>Решение:</b> добавьте бота обратно в группу.",
            ]);
        }

        if (str_contains($lower, 'chat not found')) {
            return implode("\n", [
                "❓ <b>Группа не найдена</b>",
                "Возможно, группа удалена или бот не состоит в ней.",
                '',
                "✅ <b>Решение:</b> проверьте группу и заново добавьте бота.",
            ]);
        }

        if (str_contains($lower, 'blocked')) {
            return implode("\n", [
                "⛔ <b>Бот заблокирован</b>",
                '',
                "✅ <b>Решение:</b> разблокируйте бота.",
            ]);
        }

        if (str_contains($lower, 'deactivated') || str_contains($lower, 'chat was upgraded')) {
            return implode("\n", [
                "♻️ <b>Группа изменилась</b>",
                "Группа была преобразована в супергруппу, ID изменился.",
                '',
                "✅ <b>Решение:</b> заново добавьте бота в группу.",
            ]);
        }

        return implode("\n", [
            "❌ <b>Ошибка отправки</b>",
            "<code>{$msg}</code>",
            '',
            "✅ <b>Решение:</b> проверьте права бота в группе или обратитесь к администратору.",
        ]);
    }
}
