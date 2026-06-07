<?php

namespace App\Http\Controllers;

use App\Models\BotGroup;
use App\Models\DriverBot;
use App\Models\Template;
use App\Services\Bot\DriverBotService;
use App\Services\Telegram\TelegramClientFactory;
use App\Services\Telegram\TelegramSenderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DriverBotWebhookController extends Controller
{
    private TelegramSenderService $sender;

    public function __construct(
        private readonly DriverBotService $botService,
        private readonly TelegramClientFactory $factory,
    ) {}

    public function handle(Request $request, DriverBot $driverBot): Response
    {
        $this->sender = $this->factory->make($driverBot->bot_token);
        $update       = $request->all();

        defer(function () use ($update, $driverBot) {
            if (isset($update['my_chat_member'])) {
                $this->handleMyChatMember($driverBot, $update['my_chat_member']);
                return;
            }

            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($driverBot, $update['callback_query']);
                return;
            }

            $message = $update['message'] ?? null;
            if (!$message) return;

            if (isset($message['new_chat_members'])) {
                $this->handleNewChatMembers($driverBot, $message);
                return;
            }

            $text     = trim($message['text'] ?? '');
            $fromId   = (string) ($message['from']['id'] ?? '');
            $chatId   = (string) ($message['chat']['id'] ?? '');
            $chatType = $message['chat']['type'] ?? 'private';

            if ($chatType !== 'private' || $fromId !== $driverBot->chat_id) {
                return;
            }

            if (
                isset($message['forward_from_chat']) &&
                in_array($message['forward_from_chat']['type'] ?? '', ['group', 'supergroup'], true)
            ) {
                $fc    = $message['forward_from_chat'];
                $fcId  = (string) $fc['id'];
                $isNew = !$this->botService->hasGroup($driverBot, $fcId);
                $this->botService->addGroup($driverBot, $fcId, $fc['title'] ?? '', $fc['username'] ?? null);
                $title = $fc['title'] ?? $fcId;
                $this->sender->send($chatId, $isNew
                    ? "✅ Новая группа подключена: <b>{$title}</b>"
                    : "ℹ️ Группа уже подключена: <b>{$title}</b>"
                );
                return;
            }

            $pending = $driverBot->pending;
            if ($pending && !str_starts_with($text, '/')) {
                $this->handlePendingInput($driverBot, $chatId, $text, $pending);
                return;
            }

            if (str_starts_with($text, '/start')) {
                $this->sendPanel($driverBot, $chatId);
            }
        });

        return response('', 200);
    }

    // ── Callback dispatcher ───────────────────────────────────────────────────

    private function handleCallbackQuery(DriverBot $bot, array $cq): void
    {
        $callbackId = (string) $cq['id'];
        $data       = $cq['data'] ?? '';
        $chatId     = (string) ($cq['message']['chat']['id'] ?? '');
        $messageId  = (int) ($cq['message']['message_id'] ?? 0);
        $fromId     = (string) ($cq['from']['id'] ?? '');

        try { $this->sender->answerCallback($callbackId); } catch (\Throwable) {}

        if ($fromId !== $bot->chat_id) return;

        $this->botService->setPending($bot, null);
        $bot->refresh();

        match (true) {
            $data === 'refresh'                   => $this->updatePanel($bot, $chatId, $messageId),
            $data === 'back'                      => $this->updatePanel($bot, $chatId, $messageId),
            $data === 'stop'                      => $this->handleStop($bot, $chatId, $messageId),

            $data === 'start'                     => $this->showTemplateStep($bot, $chatId, $messageId),
            $data === 'grp_all'                   => $this->handleWizardAll($bot, $chatId, $messageId, true),
            $data === 'grp_none'                  => $this->handleWizardAll($bot, $chatId, $messageId, false),
            $data === 'grp_confirm'               => $this->confirmAndStart($bot, $chatId, $messageId),

            str_starts_with($data, 'tpl:')        => $this->showIntervalStep($bot, $chatId, $messageId, (int) substr($data, 4)),
            str_starts_with($data, 'int:')        => $this->storeWizardAndShowGroups($bot, $chatId, $messageId, $data),
            str_starts_with($data, 'grp_toggle:') => $this->toggleGroup($bot, $chatId, $messageId, (int) substr($data, 11)),

            $data === 'grp_list'                  => $this->showGroupList($bot, $chatId, $messageId),
            str_starts_with($data, 'grp_view:')   => $this->showGroupView($bot, $chatId, $messageId, (int) substr($data, 9)),
            str_starts_with($data, 'grp_del:')    => $this->deleteGroup($bot, $chatId, $messageId, (int) substr($data, 8)),
            str_starts_with($data, 'grp_rename:') => $this->requestGroupName($bot, $chatId, $messageId, (int) substr($data, 11)),

            $data === 'tpl_list'                  => $this->showTemplateList($bot, $chatId, $messageId),
            $data === 'tpl_add'                   => $this->requestTemplateText($bot, $chatId, $messageId, 'tpl_add'),
            str_starts_with($data, 'tpl_view:')   => $this->showTemplateView($bot, $chatId, $messageId, (int) substr($data, 9)),
            str_starts_with($data, 'tpl_edit:')   => $this->requestTemplateText($bot, $chatId, $messageId, $data),
            str_starts_with($data, 'tpl_del:')    => $this->deleteTemplate($bot, $chatId, $messageId, (int) substr($data, 8)),

            $data === 'leave_menu'                  => $this->showLeaveMenu($bot, $chatId, $messageId),
            $data === 'leave_select'                => $this->showLeaveSelect($bot, $chatId, $messageId),
            str_starts_with($data, 'leave_toggle:') => $this->handleLeaveToggle($bot, $chatId, $messageId, (int) substr($data, 13)),
            $data === 'leave_mark_all'              => $this->markAllLeave($bot, $chatId, $messageId, true),
            $data === 'leave_unmark_all'            => $this->markAllLeave($bot, $chatId, $messageId, false),
            $data === 'leave_do_selected'           => $this->executeLeaveSelected($bot, $chatId, $messageId),
            $data === 'leave_all_confirm'           => $this->showLeaveAllConfirm($bot, $chatId, $messageId),
            $data === 'leave_all_do'                => $this->executeLeaveAll($bot, $chatId, $messageId),

            default => null,
        };
    }

    // ── Bot control ───────────────────────────────────────────────────────────

    private function handleStop(DriverBot $bot, string $chatId, int $messageId): void
    {
        $this->botService->stopBot($bot);
        $bot->refresh();
        $this->updatePanel($bot, $chatId, $messageId);
    }

    // ── Start wizard ──────────────────────────────────────────────────────────

    private function showTemplateStep(DriverBot $bot, string $chatId, int $messageId): void
    {
        $templates = $bot->templates()->get();

        if ($templates->isEmpty()) {
            $this->sender->editMessage($chatId, $messageId,
                "📝 Шаблоны не добавлены.\nДобавьте шаблон в разделе «📋 Шаблоны».",
                ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'back']]]]
            );
            return;
        }

        $lines   = ['📝 <b>Выберите шаблон для отправки:</b>', ''];
        $buttons = [];

        foreach ($templates as $i => $tpl) {
            $num     = $i + 1;
            $preview = mb_strimwidth(explode("\n", strip_tags($tpl->body))[0], 0, 50, '…');
            $lines[] = "{$num}. {$preview}";
            $buttons[] = ['text' => (string) $num, 'callback_data' => "tpl:{$tpl->id}"];
        }

        $this->sender->editMessage($chatId, $messageId, implode("\n", $lines), [
            'inline_keyboard' => [
                $buttons,
                [['text' => '◀️ Назад', 'callback_data' => 'back']],
            ],
        ]);
    }

    private function showIntervalStep(DriverBot $bot, string $chatId, int $messageId, int $templateId): void
    {
        $tpl     = $bot->templates()->find($templateId);
        $preview = $tpl ? mb_strimwidth(explode("\n", strip_tags($tpl->body))[0], 0, 50, '…') : '?';

        $mk = fn(string $label, int $s) => [
            'text' => $label, 'callback_data' => "int:{$templateId}:{$s}",
        ];

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            '⏱ <b>Как часто отправлять сообщение?</b>',
            '',
            "📝 Выбран: <i>{$preview}</i>",
        ]), [
            'inline_keyboard' => [
                [$mk('5 сек', 5),   $mk('10 сек', 10), $mk('15 сек', 15)],
                [$mk('30 сек', 30), $mk('45 сек', 45)],
                [$mk('1 мин', 60),  $mk('2 мин', 120)],
                [['text' => '◀️ Назад', 'callback_data' => 'start']],
            ],
        ]);
    }

    private function storeWizardAndShowGroups(DriverBot $bot, string $chatId, int $messageId, string $data): void
    {
        [, $templateId, $interval] = explode(':', $data);
        $this->botService->setWizardTemplate($bot, (int) $templateId);
        $this->botService->setWizardInterval($bot, (int) $interval);
        $this->botService->initWizardGroups($bot);
        $bot->refresh();
        $this->showGroupStep($bot, $chatId, $messageId);
    }

    private function showGroupStep(DriverBot $bot, string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildGroupStep($bot);
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function buildGroupStep(DriverBot $bot): array
    {
        $groups = $bot->groups()->get();
        $tplId  = $bot->wizard_template_id;

        if ($groups->isEmpty()) {
            return [
                implode("\n", [
                    '📍 <b>Группы не найдены</b>', '',
                    'Добавьте бота в группу или перешлите сообщение из группы.',
                ]),
                ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => "tpl:{$tplId}"]]]],
            ];
        }

        $lines         = ['📍 <b>Выберите группы для отправки:</b>', ''];
        $buttons       = [];
        $selectedCount = 0;

        foreach ($groups as $group) {
            $check = $group->wizard_selected ? '☑️' : '☐';
            if ($group->wizard_selected) $selectedCount++;
            $lines[]   = "{$check} {$group->displayTitle()}";
            $buttons[] = ['text' => "{$check} {$group->displayTitle()}", 'callback_data' => "grp_toggle:{$group->id}"];
        }

        $startLabel = $selectedCount > 0 ? "🚀 Запустить ({$selectedCount} групп)" : '🚀 Выберите группы';

        return [
            implode("\n", $lines),
            ['inline_keyboard' => array_merge(
                array_chunk($buttons, 2),
                [[
                    ['text' => '☑️ Все',      'callback_data' => 'grp_all'],
                    ['text' => '☐ Ни одной', 'callback_data' => 'grp_none'],
                ]],
                [[['text' => $startLabel,    'callback_data' => 'grp_confirm']]],
                [[['text' => '◀️ Назад',      'callback_data' => "tpl:{$tplId}"]]]
            )],
        ];
    }

    private function toggleGroup(DriverBot $bot, string $chatId, int $messageId, int $groupId): void
    {
        $this->botService->toggleWizardGroup($bot, $groupId);
        $bot->refresh();
        $this->showGroupStep($bot, $chatId, $messageId);
    }

    private function handleWizardAll(DriverBot $bot, string $chatId, int $messageId, bool $all): void
    {
        $this->botService->setWizardAllGroups($bot, $all);
        $bot->refresh();
        $this->showGroupStep($bot, $chatId, $messageId);
    }

    private function confirmAndStart(DriverBot $bot, string $chatId, int $messageId): void
    {
        $bot->refresh();
        if (!$this->botService->startBot($bot)) {
            $this->showGroupStep($bot, $chatId, $messageId);
            return;
        }
        $bot->refresh();
        $this->updatePanel($bot, $chatId, $messageId);
    }

    // ── Groups management ─────────────────────────────────────────────────────

    private function showGroupList(DriverBot $bot, string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildGroupList($bot);
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function sendGroupList(DriverBot $bot, string $chatId): void
    {
        [$text, $keyboard] = $this->buildGroupList($bot);
        $this->sender->send($chatId, $text, $keyboard);
    }

    private function buildGroupList(DriverBot $bot): array
    {
        $groups = $bot->groups()->get();

        if ($groups->isEmpty()) {
            return [
                implode("\n", [
                    '📋 <b>Группы (0)</b>', '',
                    'Группы не подключены.', '',
                    '<b>Как подключить группу:</b>',
                    '1️⃣ Перешлите любое сообщение из группы боту',
                    '2️⃣ Удалите бота из группы и добавьте снова',
                ]),
                ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'back']]]],
            ];
        }

        $count   = $groups->count();
        $lines   = ["📋 <b>Группы ({$count})</b>", ''];
        $buttons = [];

        foreach ($groups as $group) {
            $lines[]   = "• {$group->displayTitle()}";
            $buttons[] = ['text' => $group->displayTitle(), 'callback_data' => "grp_view:{$group->id}"];
        }

        $rows   = array_chunk($buttons, 2);
        $rows[] = [['text' => '◀️ Назад', 'callback_data' => 'back']];

        return [implode("\n", $lines), ['inline_keyboard' => $rows]];
    }

    private function showGroupView(DriverBot $bot, string $chatId, int $messageId, int $groupId): void
    {
        $group = $bot->groups()->find($groupId);

        if (!$group) {
            $this->showGroupList($bot, $chatId, $messageId);
            return;
        }

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            "📍 <b>{$group->displayTitle()}</b>",
            '',
            "🆔 <code>{$group->group_chat_id}</code>",
        ]), [
            'inline_keyboard' => [
                [['text' => '✏️ Переименовать', 'callback_data' => "grp_rename:{$group->id}"]],
                [['text' => '🗑 Удалить',        'callback_data' => "grp_del:{$group->id}"]],
                [['text' => '◀️ Назад',           'callback_data' => 'grp_list']],
            ],
        ]);
    }

    private function deleteGroup(DriverBot $bot, string $chatId, int $messageId, int $groupId): void
    {
        $group = $bot->groups()->find($groupId);
        if ($group) $this->botService->removeGroup($bot, $group->group_chat_id);
        $bot->refresh();
        $this->showGroupList($bot, $chatId, $messageId);
    }

    private function requestGroupName(DriverBot $bot, string $chatId, int $messageId, int $groupId): void
    {
        $group   = $bot->groups()->find($groupId);
        $current = $group ? $group->displayTitle() : '?';

        $this->botService->setPending($bot, "grp_rename:{$groupId}");

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            '✏️ <b>Введите новое название группы</b>',
            '',
            "Текущее: <i>{$current}</i>",
        ]), [
            'inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => "grp_view:{$groupId}"]]],
        ]);
    }

    // ── Template management ───────────────────────────────────────────────────

    private function showTemplateList(DriverBot $bot, string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildTemplateList($bot);
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function sendTemplateList(DriverBot $bot, string $chatId): void
    {
        [$text, $keyboard] = $this->buildTemplateList($bot);
        $this->sender->send($chatId, $text, $keyboard);
    }

    private function buildTemplateList(DriverBot $bot): array
    {
        $templates = $bot->templates()->get();
        $count     = $templates->count();
        $lines     = ["📋 <b>Шаблоны ({$count})</b>", ''];
        $buttons   = [];

        foreach ($templates as $i => $tpl) {
            $num     = $i + 1;
            $preview = mb_strimwidth(explode("\n", strip_tags($tpl->body))[0], 0, 40, '…');
            $lines[] = "{$num}. {$preview}";
            $buttons[] = ['text' => (string) $num, 'callback_data' => "tpl_view:{$tpl->id}"];
        }

        $rows   = array_chunk($buttons, 4);
        $rows[] = [['text' => '➕ Добавить шаблон', 'callback_data' => 'tpl_add']];
        $rows[] = [['text' => '◀️ Назад',            'callback_data' => 'back']];

        return [implode("\n", $lines), ['inline_keyboard' => $rows]];
    }

    private function showTemplateView(DriverBot $bot, string $chatId, int $messageId, int $tplId): void
    {
        $tpl = $bot->templates()->find($tplId);

        if (!$tpl) {
            $this->showTemplateList($bot, $chatId, $messageId);
            return;
        }

        $this->sender->editMessage($chatId, $messageId,
            "📝 <b>Шаблон #{$tpl->id}</b>\n\n{$tpl->body}",
            [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ Редактировать', 'callback_data' => "tpl_edit:{$tpl->id}"],
                        ['text' => '🗑 Удалить',        'callback_data' => "tpl_del:{$tpl->id}"],
                    ],
                    [['text' => '◀️ Назад', 'callback_data' => 'tpl_list']],
                ],
            ]
        );
    }

    private function requestTemplateText(DriverBot $bot, string $chatId, int $messageId, string $pendingKey): void
    {
        $this->botService->setPending($bot, $pendingKey);

        $heading = str_starts_with($pendingKey, 'tpl_edit:')
            ? '✏️ <b>Отправьте новый текст шаблона</b>'
            : '✏️ <b>Отправьте текст шаблона</b>';

        $this->sender->editMessage($chatId, $messageId, $heading, [
            'inline_keyboard' => [[['text' => '❌ Отмена', 'callback_data' => 'tpl_list']]],
        ]);
    }

    private function deleteTemplate(DriverBot $bot, string $chatId, int $messageId, int $tplId): void
    {
        $tpl = $bot->templates()->find($tplId);
        if ($tpl) {
            $this->botService->deleteTemplate($bot, $tpl);
            $bot->refresh();
        }
        $this->showTemplateList($bot, $chatId, $messageId);
    }

    // ── Pending text input ────────────────────────────────────────────────────

    private function handlePendingInput(DriverBot $bot, string $chatId, string $text, string $pending): void
    {
        $this->botService->setPending($bot, null);

        if ($pending === 'tpl_add') {
            $this->botService->addTemplate($bot, $text);
            $this->sender->send($chatId, '✅ Шаблон добавлен!');
            $bot->refresh();
            $this->sendTemplateList($bot, $chatId);
        } elseif (str_starts_with($pending, 'tpl_edit:')) {
            $tpl = $bot->templates()->find((int) substr($pending, 9));
            if ($tpl) {
                $this->botService->updateTemplate($tpl, $text);
                $this->sender->send($chatId, '✅ Шаблон обновлён!');
            }
            $bot->refresh();
            $this->sendTemplateList($bot, $chatId);
        } elseif (str_starts_with($pending, 'grp_rename:')) {
            $group = $bot->groups()->find((int) substr($pending, 11));
            if ($group) {
                $this->botService->renameGroup($bot, $group->group_chat_id, $text);
                $this->sender->send($chatId, "✅ Название группы обновлено: <b>{$text}</b>");
            }
            $bot->refresh();
            $this->sendGroupList($bot, $chatId);
        }
    }

    // ── Panel ─────────────────────────────────────────────────────────────────

    private function sendPanel(DriverBot $bot, string $chatId): void
    {
        [$text, $keyboard] = $this->buildPanel($bot);
        $this->sender->send($chatId, $text, $keyboard);
    }

    private function updatePanel(DriverBot $bot, string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildPanel($bot);
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function buildPanel(DriverBot $bot): array
    {
        $isActive     = $bot->is_active;
        $groups       = $bot->groups()->get();
        $activeGroups = $groups->where('run_selected', true);
        $groupCount   = $groups->count();
        $activeCount  = $activeGroups->count();

        $statusLine = $isActive ? '🟢 Активен' : '🔴 Остановлен';

        if ($isActive && $activeCount > 0) {
            $names     = $activeGroups->map(fn($g) => $g->displayTitle())->take(2)->implode(', ');
            $suffix    = $activeCount > 2 ? ' +' . ($activeCount - 2) : '';
            $groupLine = "📍 Активные группы: {$names}{$suffix}";
        } elseif ($groupCount > 0) {
            $groupLine = "📍 Группы: {$groupCount} подключено";
        } else {
            $groupLine = '📍 Группы: ❌ Не подключены';
        }

        $templateCount = $bot->templates()->count();
        $interval      = $this->formatInterval(max(5, $bot->interval));

        $text = implode("\n", [
            '🤖 <b>Панель управления</b>',
            '',
            "📊 Статус: {$statusLine}",
            $groupLine,
            "⏱ Интервал: {$interval}",
        ]);

        $actionBtn = $isActive
            ? ['text' => '🛑 Остановить',  'callback_data' => 'stop']
            : ['text' => '▶️ Запустить',   'callback_data' => 'start'];

        $buttons = [
            [$actionBtn],
            [
                ['text' => "📋 Группы ({$groupCount})",   'callback_data' => 'grp_list'],
                ['text' => "📋 Шаблоны ({$templateCount})", 'callback_data' => 'tpl_list'],
            ],
        ];

        if ($groupCount > 0) {
            $buttons[] = [['text' => '🚪 Покинуть группы', 'callback_data' => 'leave_menu']];
        }

        $buttons[] = [['text' => '🔄 Обновить', 'callback_data' => 'refresh']];

        return [$text, ['inline_keyboard' => $buttons]];
    }

    private function formatInterval(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds} сек";
        $min = intdiv($seconds, 60);
        return $min === 1 ? '1 мин' : "{$min} мин";
    }

    // ── Leave groups ──────────────────────────────────────────────────────────

    private function showLeaveMenu(DriverBot $bot, string $chatId, int $messageId): void
    {
        $count = $bot->groups()->count();

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            '🚪 <b>Выход из групп</b>',
            '',
            "📍 Всего подключённых групп: <b>{$count}</b>",
            '',
            'Выберите режим:',
        ]), [
            'inline_keyboard' => [
                [['text' => '📋 Выбрать группы',     'callback_data' => 'leave_select']],
                [['text' => '🗑 Покинуть ВСЕ группы', 'callback_data' => 'leave_all_confirm']],
                [['text' => '◀️ Назад',                'callback_data' => 'back']],
            ],
        ]);
    }

    private function showLeaveSelect(DriverBot $bot, string $chatId, int $messageId): void
    {
        $this->botService->clearLeaveSelection($bot);
        $bot->refresh();
        $this->renderLeaveSelect($bot, $chatId, $messageId);
    }

    private function renderLeaveSelect(DriverBot $bot, string $chatId, int $messageId): void
    {
        $groups        = $bot->groups()->orderBy('title')->get();
        $selectedCount = $groups->where('leave_selected', true)->count();

        $buttons = [];
        foreach ($groups as $group) {
            $mark  = $group->leave_selected ? '☑' : '☐';
            $title = mb_strimwidth($group->displayTitle(), 0, 40, '…');
            $buttons[] = [['text' => "{$mark} {$title}", 'callback_data' => "leave_toggle:{$group->id}"]];
        }

        $buttons[] = [
            ['text' => '✅ Отметить все', 'callback_data' => 'leave_mark_all'],
            ['text' => '⬜ Снять все',     'callback_data' => 'leave_unmark_all'],
        ];
        if ($selectedCount > 0) {
            $buttons[] = [['text' => "🚪 Покинуть отмеченные ({$selectedCount})", 'callback_data' => 'leave_do_selected']];
        }
        $buttons[] = [['text' => '◀️ Назад', 'callback_data' => 'back']];

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            '📋 <b>Выберите группы для выхода</b>',
            '',
            "Отмечено: <b>{$selectedCount}</b>",
        ]), [
            'inline_keyboard' => $buttons,
        ]);
    }

    private function handleLeaveToggle(DriverBot $bot, string $chatId, int $messageId, int $groupId): void
    {
        $this->botService->toggleLeaveGroup($bot, $groupId);
        $bot->refresh();
        $this->renderLeaveSelect($bot, $chatId, $messageId);
    }

    private function markAllLeave(DriverBot $bot, string $chatId, int $messageId, bool $value): void
    {
        $this->botService->setLeaveAllGroups($bot, $value);
        $bot->refresh();
        $this->renderLeaveSelect($bot, $chatId, $messageId);
    }

    private function showLeaveAllConfirm(DriverBot $bot, string $chatId, int $messageId): void
    {
        $count = $bot->groups()->count();

        if ($count === 0) {
            $this->updatePanel($bot, $chatId, $messageId);
            return;
        }

        $this->sender->editMessage($chatId, $messageId, implode("\n", [
            '⚠️ <b>Подтверждение</b>',
            '',
            "Бот покинет <b>ВСЕ {$count} групп</b> и они будут удалены из списка.",
            '',
            'Это действие необратимо.',
        ]), [
            'inline_keyboard' => [
                [['text' => '✅ Да, покинуть все', 'callback_data' => 'leave_all_do']],
                [['text' => '❌ Отмена',           'callback_data' => 'leave_menu']],
            ],
        ]);
    }

    private function executeLeaveSelected(DriverBot $bot, string $chatId, int $messageId): void
    {
        $groups = $bot->groups()->where('leave_selected', true)->get();

        if ($groups->isEmpty()) {
            $this->sender->editMessage($chatId, $messageId, '⚠️ Не выбрано ни одной группы.', [
                'inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'leave_select']]],
            ]);
            return;
        }

        $this->doLeave($bot, $groups, $chatId, $messageId);
    }

    private function executeLeaveAll(DriverBot $bot, string $chatId, int $messageId): void
    {
        $groups = $bot->groups()->get();

        if ($groups->isEmpty()) {
            $this->updatePanel($bot, $chatId, $messageId);
            return;
        }

        $this->doLeave($bot, $groups, $chatId, $messageId);
    }

    private function doLeave(DriverBot $bot, $groups, string $chatId, int $messageId): void
    {
        $total = $groups->count();

        try {
            $this->sender->editMessage(
                $chatId,
                $messageId,
                "🚪 Выход из <b>{$total}</b> групп...\n\nПожалуйста, подождите ~{$total} сек."
            );
        } catch (\Throwable) {}

        $left = 0; $failed = 0;
        foreach ($groups as $group) {
            try {
                $this->sender->leaveChat($group->group_chat_id);
                $group->delete();
                $left++;
            } catch (\Throwable) {
                $failed++;
            }
            usleep(500_000); // 0.5s pacing to stay under Telegram rate limits
        }

        // Stop the bot if no groups remain
        if ($bot->groups()->count() === 0 && $bot->is_active) {
            $this->botService->stopBot($bot);
        }
        $bot->refresh();

        $lines = [
            '✅ <b>Готово</b>',
            '',
            "🚪 Покинуто: <b>{$left}</b> из {$total}",
        ];
        if ($failed > 0) {
            $lines[] = "❌ Не удалось покинуть: <b>{$failed}</b>";
        }

        $this->sender->editMessage($chatId, $messageId, implode("\n", $lines), [
            'inline_keyboard' => [[['text' => '◀️ В панель', 'callback_data' => 'back']]],
        ]);
    }

    // ── Telegram membership events ────────────────────────────────────────────

    private function handleMyChatMember(DriverBot $bot, array $myChatMember): void
    {
        $chat      = $myChatMember['chat'] ?? [];
        $chatType  = $chat['type'] ?? '';
        $newStatus = $myChatMember['new_chat_member']['status'] ?? '';

        if (
            in_array($chatType, ['group', 'supergroup'], true) &&
            in_array($newStatus, ['member', 'administrator'], true)
        ) {
            $id       = (string) $chat['id'];
            $title    = $chat['title'] ?? '';
            $username = $chat['username'] ?? null;
            $isNew    = !$this->botService->hasGroup($bot, $id);
            $this->botService->addGroup($bot, $id, $title, $username);

            if ($isNew) {
                $this->sender->send(
                    $bot->chat_id,
                    "✅ Новая группа подключена: <b>{$title}</b>\n🆔 <code>{$id}</code>"
                );
            }
        }
    }

    private function handleNewChatMembers(DriverBot $bot, array $message): void
    {
        $chat     = $message['chat'] ?? [];
        $chatType = $chat['type'] ?? '';

        if (!in_array($chatType, ['group', 'supergroup'], true)) return;

        $botId    = (int) explode(':', $bot->bot_token)[0];
        $newUsers = $message['new_chat_members'] ?? [];

        foreach ($newUsers as $user) {
            if ((int) $user['id'] === $botId) {
                $id       = (string) $chat['id'];
                $title    = $chat['title'] ?? '';
                $username = $chat['username'] ?? null;
                $isNew    = !$this->botService->hasGroup($bot, $id);
                $this->botService->addGroup($bot, $id, $title, $username);

                if ($isNew) {
                    $this->sender->send(
                        $bot->chat_id,
                        "✅ Новая группа подключена: <b>{$title}</b>\n🆔 <code>{$id}</code>"
                    );
                }
                break;
            }
        }
    }
}
