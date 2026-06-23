<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMasterUpdate;
use App\Models\DriverBot;
use App\Services\Bot\MasterBotService;
use App\Services\Telegram\TelegramClientFactory;
use App\Services\Telegram\TelegramSenderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MasterBotWebhookController extends Controller
{
    private TelegramSenderService $sender;

    public function __construct(
        private readonly MasterBotService $masterService,
        private readonly TelegramClientFactory $factory,
    ) {
        $this->sender = $factory->master();
    }

    /**
     * Telegram webhook entry point. Returns 200 instantly and hands the slow
     * Telegram API work to the dedicated "master" queue worker, so the web
     * worker is never blocked and the admin panel stays responsive even while
     * bot:run is broadcasting.
     */
    public function handle(Request $request): Response
    {
        ProcessMasterUpdate::dispatch($request->all())->onQueue('master');

        return response('', 200);
    }

    /**
     * Runs inside the queue worker (see ProcessMasterUpdate). Contains the
     * actual update handling that used to live in the defer() block.
     */
    public function process(array $update): void
    {
        $adminId = (string) env('TELEGRAM_ADMIN_ID');

        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query'], $adminId);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) return;

        $fromId = (string) ($message['from']['id'] ?? '');
        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($fromId !== $adminId) {
            $this->sender->send($chatId, '⛔ Нет доступа');
            return;
        }

        $text    = trim($message['text'] ?? '');
        $pending = $this->masterService->getPending();

        if ($pending && !str_starts_with($text, '/')) {
            $this->handlePendingInput($chatId, $text, $pending);
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->sendPanel($chatId);
        }
    }

    // ── Callback dispatcher ───────────────────────────────────────────────────

    private function handleCallbackQuery(array $cq, string $adminId): void
    {
        $callbackId = (string) $cq['id'];
        $data       = $cq['data'] ?? '';
        $chatId     = (string) ($cq['message']['chat']['id'] ?? '');
        $messageId  = (int) ($cq['message']['message_id'] ?? 0);
        $fromId     = (string) ($cq['from']['id'] ?? '');

        try { $this->sender->answerCallback($callbackId); } catch (\Throwable) {}

        if ($fromId !== $adminId) return;

        $this->masterService->setPending(null);

        match (true) {
            $data === 'refresh'                         => $this->updatePanel($chatId, $messageId),
            $data === 'back'                            => $this->updatePanel($chatId, $messageId),
            $data === 'driver_add'                      => $this->requestDriverName($chatId, $messageId),
            str_starts_with($data, 'driver_view:')      => $this->showDriverView($chatId, $messageId, (int) substr($data, 12)),
            str_starts_with($data, 'driver_del:')       => $this->deleteDriver($chatId, $messageId, (int) substr($data, 11)),
            str_starts_with($data, 'driver_edit_name:') => $this->requestEditField($chatId, $messageId, 'name', (int) substr($data, 17)),
            str_starts_with($data, 'driver_edit_chat:') => $this->requestEditField($chatId, $messageId, 'chat_id', (int) substr($data, 17)),
            str_starts_with($data, 'driver_sessions:')  => $this->showDriverSessions($chatId, $messageId, (int) substr($data, 16)),
            default                                     => null,
        };
    }

    // ── Driver add wizard ─────────────────────────────────────────────────────

    private function requestDriverName(string $chatId, int $messageId): void
    {
        $this->masterService->setPending('add_name');

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            '👤 <b>Добавление водителя</b>',
            '',
            '1️⃣ Введите имя водителя:',
        ]), [
            'inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'back']]],
        ]);
    }

    private function handlePendingInput(string $chatId, string $text, string $pending): void
    {
        $state = $this->masterService->getState();

        // ── Add wizard ────────────────────────────────────────────────────────
        if ($pending === 'add_name') {
            $this->masterService->setWizardField('wizard_name', $text);
            $this->masterService->setPending('add_chat_id');
            $this->sender->send($chatId, implode("\n", [
                "✅ Имя: <b>{$text}</b>",
                '',
                '2️⃣ Введите <b>chat_id</b> водителя:',
                '',
                '<i>Узнать chat_id: @userinfobot → /start</i>',
            ]));
            return;
        }

        if ($pending === 'add_chat_id') {
            $this->masterService->setWizardField('wizard_chat_id', $text);
            $this->masterService->setPending('add_token');
            $this->sender->send($chatId, implode("\n", [
                "✅ Chat ID: <code>{$text}</code>",
                '',
                '3️⃣ Введите <b>токен бота</b> водителя:',
                '',
                '<i>@BotFather → /newbot → скопируйте токен</i>',
            ]));
            return;
        }

        if ($pending === 'add_token') {
            $this->masterService->setPending(null);

            try {
                $botInfo = $this->masterService->validateToken($text);
            } catch (\Throwable $e) {
                $this->masterService->clearWizard();
                $this->sender->send($chatId, "❌ Неверный токен: <code>{$e->getMessage()}</code>\n\nНачните заново.");
                $this->sendPanel($chatId);
                return;
            }

            if (DriverBot::where('bot_token', $text)->exists()) {
                $this->masterService->clearWizard();
                $this->sender->send($chatId, '❌ Этот токен уже зарегистрирован.');
                $this->sendPanel($chatId);
                return;
            }

            $name    = $state['wizard_name'];
            $driverChatId = $state['wizard_chat_id'];

            $bot = $this->masterService->registerDriver($name, $driverChatId, $text, $botInfo['username']);
            $this->masterService->clearWizard();

            $baseUrl    = rtrim(env('WEBHOOK_BASE_URL', config('app.url')), '/');
            $webhookUrl = "{$baseUrl}/api/webhook/driver/{$bot->id}";

            $driverApi = $this->factory->make($text)->getApi();

            try {
                $driverApi->setWebhook([
                    'url'             => $webhookUrl,
                    'allowed_updates' => json_encode(['message', 'my_chat_member', 'callback_query']),
                ]);
                $webhookStatus = '✅ Вебхук установлен';
            } catch (\Throwable $e) {
                $webhookStatus = "⚠️ Ошибка вебхука: {$e->getMessage()}";
            }

            try { $driverApi->deleteMyCommands(); } catch (\Throwable) {}

            try {
                $this->factory->make($text)->send($driverChatId, implode("\n", [
                    "🎉 <b>Ваш бот готов к работе!</b>",
                    '',
                    "Здравствуйте, <b>{$name}</b>!",
                    'Напишите /start для начала работы.',
                ]));
            } catch (\Throwable) {}

            $this->sender->send($chatId, implode("\n", [
                '✅ <b>Водитель добавлен!</b>',
                '',
                "👤 Имя: <b>{$name}</b>",
                "🆔 Chat ID: <code>{$driverChatId}</code>",
                "🤖 Бот: @{$botInfo['username']}",
                $webhookStatus,
            ]));
            $this->sendPanel($chatId);
            return;
        }

        // ── Edit fields ───────────────────────────────────────────────────────
        if (str_starts_with($pending, 'edit_name:')) {
            $driverId = (int) substr($pending, 10);
            $bot      = DriverBot::find($driverId);
            if ($bot) {
                $old = $bot->name;
                $bot->update(['name' => $text]);
                $this->sender->send($chatId, "✅ Имя изменено: <b>{$old}</b> → <b>{$text}</b>");
            }
            $this->sendPanel($chatId);
            return;
        }

        if (str_starts_with($pending, 'edit_chat:')) {
            $driverId = (int) substr($pending, 10);
            $bot      = DriverBot::find($driverId);
            if ($bot) {
                $old = $bot->chat_id;
                $bot->update(['chat_id' => $text]);
                $this->sender->send($chatId, "✅ Chat ID изменён: <code>{$old}</code> → <code>{$text}</code>");
            }
            $this->sendPanel($chatId);
            return;
        }
    }

    // ── Driver view & edit ────────────────────────────────────────────────────

    private function showDriverView(string $chatId, int $messageId, int $driverId): void
    {
        $bot = DriverBot::find($driverId);

        if (!$bot) {
            $this->updatePanel($chatId, $messageId);
            return;
        }

        $status   = $bot->is_active ? '🟢 Активен' : '🔴 Остановлен';
        $username = $bot->bot_username ? "@{$bot->bot_username}" : '—';
        $lastSent = $bot->last_sent_at
            ? $bot->last_sent_at->setTimezone('Asia/Tashkent')->format('d.m.Y H:i:s')
            : 'Ещё не отправлял';

        $groupsBlock = $this->buildGroupsBlock($bot);

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            "👤 <b>{$bot->name}</b>",
            '',
            "📊 Статус: {$status}",
            "🤖 Бот: {$username}",
            "🆔 Chat ID: <code>{$bot->chat_id}</code>",
            "📝 Шаблонов: {$bot->templates()->count()}",
            "🕐 Последняя отправка: {$lastSent}",
            '',
            "📋 <b>Группы ({$bot->groups()->count()}):</b>",
            $groupsBlock,
        ]), [
            'inline_keyboard' => [
                [['text' => '🕐 История использования', 'callback_data' => "driver_sessions:{$bot->id}"]],
                [
                    ['text' => '✏️ Изменить имя',    'callback_data' => "driver_edit_name:{$bot->id}"],
                    ['text' => '✏️ Изменить chat_id', 'callback_data' => "driver_edit_chat:{$bot->id}"],
                ],
                [['text' => '🗑 Удалить водителя',    'callback_data' => "driver_del:{$bot->id}"]],
                [['text' => '◀️ Назад',               'callback_data' => 'back']],
            ],
        ]);
    }

    private function showDriverSessions(string $chatId, int $messageId, int $driverId): void
    {
        $bot = DriverBot::find($driverId);
        if (!$bot) {
            $this->updatePanel($chatId, $messageId);
            return;
        }

        $sessions = $bot->sessions()->orderByDesc('started_at')->limit(5)->get();

        $lines = [
            '🕐 <b>История использования</b>',
            "👤 <b>{$bot->name}</b>",
            '',
        ];

        if ($sessions->isEmpty()) {
            $lines[] = '<i>Сессий пока нет. Они появятся, когда водитель запустит бота.</i>';
        } else {
            $lines[] = "Последние {$sessions->count()} сессий (время Asia/Tashkent):";
            $lines[] = '';

            foreach ($sessions as $i => $s) {
                $num   = $i + 1;
                $start = $s->started_at->setTimezone('Asia/Tashkent');

                if ($s->stopped_at) {
                    $stop      = $s->stopped_at->setTimezone('Asia/Tashkent');
                    $minutes   = (int) round(($stop->getTimestamp() - $start->getTimestamp()) / 60);
                    $duration  = $this->formatDuration($minutes);
                    $lines[]   = "{$num}. <b>{$start->format('d.m')}</b>  {$start->format('H:i')} → {$stop->format('H:i')}  <i>({$duration})</i>";
                } else {
                    $minutes  = (int) round((now()->getTimestamp() - $start->getTimestamp()) / 60);
                    $duration = $this->formatDuration($minutes);
                    $lines[]  = "{$num}. <b>{$start->format('d.m')}</b>  {$start->format('H:i')} → 🟢 в работе  <i>({$duration})</i>";
                }
            }
        }

        $this->sender->editMessage($chatId, $messageId, implode("\n", $lines), [
            'inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => "driver_view:{$bot->id}"]]],
        ]);
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes < 1)  return 'меньше минуты';
        if ($minutes < 60) return "{$minutes} мин";
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m ? "{$h}ч {$m}м" : "{$h}ч";
    }

    private function buildGroupsBlock(DriverBot $bot): string
    {
        $groups = $bot->groups()->orderByDesc('run_selected')->orderBy('title')->get();

        if ($groups->isEmpty()) {
            return '<i>Нет групп</i>';
        }

        $lines = [];
        foreach ($groups as $group) {
            $marker = $group->run_selected ? '🟢' : '⚪';
            $title  = $group->title ?: $group->group_chat_id;
            $uname  = $group->username ? " — @{$group->username}" : '';
            $lines[] = "{$marker} {$title}{$uname}";
        }

        return implode("\n", $lines);
    }

    private function requestEditField(string $chatId, int $messageId, string $field, int $driverId): void
    {
        $bot = DriverBot::find($driverId);
        if (!$bot) {
            $this->updatePanel($chatId, $messageId);
            return;
        }

        if ($field === 'name') {
            $this->masterService->setPending("edit_name:{$driverId}");
            $this->sender->editMessage($chatId, $messageId, implode("\n", [
                '✏️ <b>Изменить имя водителя</b>',
                '',
                "Текущее: <b>{$bot->name}</b>",
                '',
                'Введите новое имя:',
            ]), [
                'inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => "driver_view:{$driverId}"]]],
            ]);
        } else {
            $this->masterService->setPending("edit_chat:{$driverId}");
            $this->sender->editMessage($chatId, $messageId, implode("\n", [
                '✏️ <b>Изменить chat_id водителя</b>',
                '',
                "Текущий: <code>{$bot->chat_id}</code>",
                '',
                'Введите новый chat_id:',
            ]), [
                'inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => "driver_view:{$driverId}"]]],
            ]);
        }
    }

    private function deleteDriver(string $chatId, int $messageId, int $driverId): void
    {
        $bot = DriverBot::find($driverId);

        if ($bot) {
            try { $this->factory->make($bot->bot_token)->getApi()->deleteWebhook(); } catch (\Throwable) {}
            $name = $bot->name;
            $this->masterService->removeDriver($bot);
            $this->sender->send($chatId, "✅ Водитель <b>{$name}</b> удалён.");
        }

        $this->updatePanel($chatId, $messageId);
    }

    // ── Panel ─────────────────────────────────────────────────────────────────

    private function sendPanel(string $chatId): void
    {
        [$text, $keyboard] = $this->buildPanel();
        $this->sender->send($chatId, $text, $keyboard);
    }

    private function updatePanel(string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildPanel();
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function buildPanel(): array
    {
        $drivers = $this->masterService->getAllDrivers();
        $count   = $drivers->count();
        $active  = $drivers->where('is_active', true)->count();

        $lines   = [
            '🤖 <b>Панель администратора</b>',
            '',
            "👥 Водителей: {$count}  |  🟢 Активных: {$active}",
            '',
        ];

        $buttons = [];

        foreach ($drivers as $bot) {
            $icon     = $bot->is_active ? '🟢' : '🔴';
            $username = $bot->bot_username ? " (@{$bot->bot_username})" : '';
            $lines[]   = "{$icon} {$bot->name}{$username}";
            $buttons[] = [['text' => "{$icon} {$bot->name}", 'callback_data' => "driver_view:{$bot->id}"]];
        }

        $buttons[] = [['text' => '➕ Добавить водителя', 'callback_data' => 'driver_add']];
        $buttons[] = [['text' => '🔄 Обновить',           'callback_data' => 'refresh']];

        return [implode("\n", $lines), ['inline_keyboard' => $buttons]];
    }
}
