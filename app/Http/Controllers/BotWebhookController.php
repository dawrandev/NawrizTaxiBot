<?php

namespace App\Http\Controllers;

use App\Services\BotStateService;
use App\Services\TemplateService;
use App\Services\TelegramSenderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BotWebhookController extends Controller
{
    public function __construct(
        private readonly BotStateService $stateService,
        private readonly TemplateService $templateService,
        private readonly TelegramSenderService $sender,
    ) {}

    public function handle(Request $request): Response
    {
        $update = $request->all();

        if (isset($update['my_chat_member'])) {
            $this->handleMyChatMember($update['my_chat_member']);

            return response('', 200);
        }

        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);

            return response('', 200);
        }

        $message = $update['message'] ?? null;

        if (!$message) {
            return response('', 200);
        }

        if (isset($message['new_chat_members'])) {
            $this->handleNewChatMembers($message);

            return response('', 200);
        }

        $text     = trim($message['text'] ?? '');
        $fromId   = (int) ($message['from']['id'] ?? 0);
        $chatId   = (string) ($message['chat']['id'] ?? '');
        $chatType = $message['chat']['type'] ?? 'private';
        $adminId  = (int) env('TELEGRAM_ADMIN_ID');

        // Auto-detect group when admin forwards any message from a group
        if (
            $fromId === $adminId &&
            isset($message['forward_from_chat']) &&
            in_array($message['forward_from_chat']['type'] ?? '', ['group', 'supergroup'], true)
        ) {
            $fc    = $message['forward_from_chat'];
            $fcId  = (string) $fc['id'];
            $isNew = !$this->stateService->hasGroup($fcId);
            $this->stateService->addGroup($fcId, $fc['title'] ?? '');

            $title = $fc['title'] ?? $fcId;
            $msg   = $isNew
                ? "✅ Yangi guruh ulandi: <b>{$title}</b>"
                : "ℹ️ Bu guruh allaqachon ulangan: <b>{$title}</b>";
            $this->sender->send($chatId, $msg);

            return response('', 200);
        }

        if ($fromId !== $adminId) {
            $this->sender->send($chatId, '⛔ Sizda ruxsat yo\'q');

            return response('', 200);
        }

        // Handle pending text input (template add / edit)
        $pending = $this->stateService->getPending();
        if ($pending && !str_starts_with($text, '/') && $chatType === 'private') {
            $this->handlePendingInput($chatId, $text, $pending);

            return response('', 200);
        }

        if (str_starts_with($text, '/start')) {
            $this->sendPanel($chatId);
        }

        return response('', 200);
    }

    // ── Callback dispatcher ───────────────────────────────────────────────────

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = (string) $callbackQuery['id'];
        $data       = $callbackQuery['data'] ?? '';
        $chatId     = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $messageId  = (int) ($callbackQuery['message']['message_id'] ?? 0);
        $fromId     = (int) ($callbackQuery['from']['id'] ?? 0);
        $adminId    = (int) env('TELEGRAM_ADMIN_ID');

        // Answer immediately — Telegram has a ~10s timeout for this
        try {
            $this->sender->answerCallback($callbackId);
        } catch (\Throwable) {
            // Callback expired (retry from Telegram) — still process the action
        }

        if ($fromId !== $adminId) {
            return;
        }

        // Any button press cancels pending text input
        $this->stateService->setPending(null);

        match (true) {
            // Panel
            $data === 'refresh'                    => $this->updatePanel($chatId, $messageId),
            $data === 'back'                       => $this->updatePanel($chatId, $messageId),
            $data === 'stop'                       => $this->handleStop($chatId, $messageId),

            // Start wizard
            $data === 'start'                      => $this->showTemplateStep($chatId, $messageId),
            $data === 'grp_all'                    => $this->setWizardAllGroups($chatId, $messageId, true),
            $data === 'grp_none'                   => $this->setWizardAllGroups($chatId, $messageId, false),
            $data === 'grp_confirm'                => $this->confirmAndStart($chatId, $messageId),
            str_starts_with($data, 'tpl:')         => $this->showIntervalStep($chatId, $messageId, (int) substr($data, 4)),
            str_starts_with($data, 'int:')         => $this->storeWizardAndShowGroups($chatId, $messageId, $data),
            str_starts_with($data, 'grp_toggle:')  => $this->toggleGroupAndRefresh($chatId, $messageId, (int) substr($data, 11)),

            // Groups management
            $data === 'grp_list'                    => $this->showGroupList($chatId, $messageId),
            str_starts_with($data, 'grp_view:')     => $this->showGroupView($chatId, $messageId, (int) substr($data, 9)),
            str_starts_with($data, 'grp_del:')      => $this->deleteGroup($chatId, $messageId, (int) substr($data, 8)),
            str_starts_with($data, 'grp_rename:')   => $this->requestGroupName($chatId, $messageId, (int) substr($data, 11)),

            // Templates management
            $data === 'tpl_list'                   => $this->showTemplateList($chatId, $messageId),
            $data === 'tpl_add'                    => $this->requestTemplateText($chatId, $messageId, 'tpl_add'),
            str_starts_with($data, 'tpl_view:')    => $this->showTemplateView($chatId, $messageId, (int) substr($data, 9)),
            str_starts_with($data, 'tpl_edit:')    => $this->requestTemplateText($chatId, $messageId, $data),
            str_starts_with($data, 'tpl_del:')     => $this->deleteTemplate($chatId, $messageId, (int) substr($data, 8)),

            default                                => null,
        };
    }

    // ── Bot control ───────────────────────────────────────────────────────────

    private function handleStop(string $chatId, int $messageId): void
    {
        $this->stateService->setActive(false);
        $this->updatePanel($chatId, $messageId);
    }

    // ── Start wizard ──────────────────────────────────────────────────────────

    private function showTemplateStep(string $chatId, int $messageId): void
    {
        $templates = $this->templateService->all();

        if (empty($templates)) {
            $this->sender->editMessage(
                $chatId, $messageId,
                "📝 Hali shablon qo'shilmagan.\n\"📋 Shablonlar\" bo'limidan shablon qo'shing.",
                ['inline_keyboard' => [[['text' => '◀️ Orqaga', 'callback_data' => 'back']]]]
            );

            return;
        }

        $lines   = ['📝 <b>Qaysi shablon yuborilsin?</b>', ''];
        $buttons = [];

        foreach ($templates as $i => $tpl) {
            $num     = $i + 1;
            $preview = explode("\n", strip_tags($tpl))[0];
            $lines[] = "{$num}. {$preview}";
            $buttons[] = ['text' => (string) $num, 'callback_data' => "tpl:{$i}"];
        }

        $keyboard = [
            'inline_keyboard' => [
                $buttons,
                [['text' => '◀️ Orqaga', 'callback_data' => 'back']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, implode("\n", $lines), $keyboard);
    }

    private function showIntervalStep(string $chatId, int $messageId, int $templateIndex): void
    {
        $templates = $this->templateService->all();
        $preview   = explode("\n", strip_tags($templates[$templateIndex] ?? ''))[0];

        $text = implode("\n", [
            '⏱ <b>Necha sekunddan bir marta yuborilsin?</b>',
            '',
            "📝 Tanlangan: <i>{$preview}</i>",
        ]);

        $mk = fn(string $label, int $secs) => [
            'text'          => $label,
            'callback_data' => "int:{$templateIndex}:{$secs}",
        ];

        $keyboard = [
            'inline_keyboard' => [
                [$mk('5 sek', 5),  $mk('10 sek', 10), $mk('20 sek', 20)],
                [$mk('30 sek', 30), $mk('1 daqiqa', 60)],
                [['text' => '◀️ Orqaga', 'callback_data' => 'start']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function storeWizardAndShowGroups(string $chatId, int $messageId, string $data): void
    {
        $parts = explode(':', $data);
        $this->stateService->setWizardTemplate((int) ($parts[1] ?? 0));
        $this->stateService->setWizardInterval((int) ($parts[2] ?? 30));

        // Default: select all known groups
        $allIds = array_column($this->stateService->getGroups(), 'id');
        $this->stateService->setWizardGroupIds($allIds);

        $this->showGroupStep($chatId, $messageId);
    }

    private function showGroupStep(string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildGroupStep();
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function buildGroupStep(): array
    {
        $state   = $this->stateService->getState();
        $groups  = $state['groups'] ?? [];
        $wizIds  = $state['wizard_group_ids'] ?? [];
        $tplIdx  = $state['wizard_template'] ?? 0;

        if (empty($groups)) {
            return [
                implode("\n", [
                    '📍 <b>Guruhlar topilmadi</b>',
                    '',
                    'Botni guruhga qo\'shing yoki',
                    'guruhdan istalgan xabarni botga forward qiling.',
                ]),
                ['inline_keyboard' => [
                    [['text' => '◀️ Orqaga', 'callback_data' => "tpl:{$tplIdx}"]],
                ]],
            ];
        }

        $lines         = ['📍 <b>Qaysi guruhlarga yuborilsin?</b>', ''];
        $buttons       = [];
        $selectedCount = 0;

        foreach ($groups as $i => $group) {
            $isSelected = in_array($group['id'], $wizIds, true);
            if ($isSelected) {
                $selectedCount++;
            }
            $check     = $isSelected ? '☑️' : '☐';
            $title     = $group['title'] ?: $group['id'];
            $lines[]   = "{$check} " . ($i + 1) . ". {$title}";
            $buttons[] = ['text' => "{$check} " . ($i + 1), 'callback_data' => "grp_toggle:{$i}"];
        }

        $startLabel = $selectedCount > 0
            ? "🚀 Boshlash ({$selectedCount} ta guruh)"
            : '🚀 Guruh tanlang';

        $keyboard = [
            'inline_keyboard' => array_merge(
                array_chunk($buttons, 4),
                [[
                    ['text' => '☑️ Barchasi', 'callback_data' => 'grp_all'],
                    ['text' => '☐ Hech biri', 'callback_data' => 'grp_none'],
                ]],
                [[['text' => $startLabel, 'callback_data' => 'grp_confirm']]],
                [[['text' => '◀️ Orqaga', 'callback_data' => "tpl:{$tplIdx}"]]]
            ),
        ];

        return [implode("\n", $lines), $keyboard];
    }

    private function toggleGroupAndRefresh(string $chatId, int $messageId, int $groupIndex): void
    {
        $this->stateService->toggleWizardGroup($groupIndex);
        $this->showGroupStep($chatId, $messageId);
    }

    private function setWizardAllGroups(string $chatId, int $messageId, bool $selectAll): void
    {
        $ids = $selectAll ? array_column($this->stateService->getGroups(), 'id') : [];
        $this->stateService->setWizardGroupIds($ids);
        $this->showGroupStep($chatId, $messageId);
    }

    private function confirmAndStart(string $chatId, int $messageId): void
    {
        $state    = $this->stateService->getState();
        $groupIds = $state['wizard_group_ids'] ?? [];

        if (empty($groupIds)) {
            $this->showGroupStep($chatId, $messageId);

            return;
        }

        $this->stateService->startBot(
            (int) ($state['wizard_template'] ?? 0),
            (int) ($state['wizard_interval'] ?? 30),
            $groupIds
        );

        $this->updatePanel($chatId, $messageId);
    }

    // ── Groups management ─────────────────────────────────────────────────────

    private function showGroupList(string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildGroupList();
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function sendGroupList(string $chatId): void
    {
        [$text, $keyboard] = $this->buildGroupList();
        $this->sender->send($chatId, $text, $keyboard);
    }

    private function buildGroupList(): array
    {
        $groups = $this->stateService->getGroups();
        $count  = count($groups);

        if (empty($groups)) {
            return [
                implode("\n", [
                    '📋 <b>Guruhlar (0 ta)</b>',
                    '',
                    'Hali guruh ulanmagan.',
                    '',
                    '<b>Guruh ulash:</b>',
                    '1️⃣ Guruhdan istalgan xabarni botga <b>forward</b> qiling',
                    '2️⃣ Botni guruhdan chiqarib qayta qo\'shing',
                ]),
                ['inline_keyboard' => [[['text' => '◀️ Orqaga', 'callback_data' => 'back']]]],
            ];
        }

        $lines   = ["📋 <b>Guruhlar ({$count} ta)</b>", ''];
        $buttons = [];

        foreach ($groups as $i => $group) {
            $title     = $group['title'] ?: $group['id'];
            $lines[]   = ($i + 1) . ". {$title}";
            $buttons[] = ['text' => (string) ($i + 1), 'callback_data' => "grp_view:{$i}"];
        }

        $rows   = array_chunk($buttons, 4);
        $rows[] = [['text' => '◀️ Orqaga', 'callback_data' => 'back']];

        return [implode("\n", $lines), ['inline_keyboard' => $rows]];
    }

    private function showGroupView(string $chatId, int $messageId, int $index): void
    {
        $groups = $this->stateService->getGroups();
        $group  = $groups[$index] ?? null;

        if (!$group) {
            $this->showGroupList($chatId, $messageId);

            return;
        }

        $title = $group['title'] ?: 'Nomsiz guruh';
        $text  = implode("\n", [
            "📍 <b>{$title}</b>",
            '',
            "🆔 <code>{$group['id']}</code>",
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✏️ Nomini o\'zgartirish', 'callback_data' => "grp_rename:{$index}"]],
                [['text' => '🗑 O\'chirish', 'callback_data' => "grp_del:{$index}"]],
                [['text' => '◀️ Orqaga', 'callback_data' => 'grp_list']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function deleteGroup(string $chatId, int $messageId, int $index): void
    {
        $groups = $this->stateService->getGroups();

        if (isset($groups[$index])) {
            $this->stateService->removeGroup($groups[$index]['id']);
        }

        $this->showGroupList($chatId, $messageId);
    }

    private function requestGroupName(string $chatId, int $messageId, int $index): void
    {
        $groups  = $this->stateService->getGroups();
        $group   = $groups[$index] ?? null;
        $current = $group ? ($group['title'] ?: $group['id']) : '?';

        $this->stateService->setPending("grp_rename:{$index}");

        $text = implode("\n", [
            '✏️ <b>Guruh nomini yuboring</b>',
            '',
            "Hozirgi nom: <i>{$current}</i>",
        ]);

        $keyboard = ['inline_keyboard' => [
            [['text' => '❌ Bekor qilish', 'callback_data' => "grp_view:{$index}"]],
        ]];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    // ── Template management ───────────────────────────────────────────────────

    private function showTemplateList(string $chatId, int $messageId): void
    {
        [$text, $keyboard] = $this->buildTemplateList();
        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function sendTemplateList(string $chatId): void
    {
        [$text, $keyboard] = $this->buildTemplateList();
        $this->sender->send($chatId, $text, $keyboard);
    }

    private function buildTemplateList(): array
    {
        $templates = $this->templateService->all();
        $count     = count($templates);
        $lines     = ["📋 <b>Shablonlar ({$count} ta)</b>", ''];
        $buttons   = [];

        foreach ($templates as $i => $tpl) {
            $num     = $i + 1;
            $preview = mb_strimwidth(explode("\n", strip_tags($tpl))[0], 0, 40, '...');
            $lines[] = "{$num}. {$preview}";
            $buttons[] = ['text' => (string) $num, 'callback_data' => "tpl_view:{$i}"];
        }

        $rows   = array_chunk($buttons, 4);
        $rows[] = [['text' => '➕ Yangi shablon', 'callback_data' => 'tpl_add']];
        $rows[] = [['text' => '◀️ Orqaga', 'callback_data' => 'back']];

        return [implode("\n", $lines), ['inline_keyboard' => $rows]];
    }

    private function showTemplateView(string $chatId, int $messageId, int $index): void
    {
        $templates = $this->templateService->all();
        $tpl       = $templates[$index] ?? null;

        if ($tpl === null) {
            $this->showTemplateList($chatId, $messageId);

            return;
        }

        $text = implode("\n", ["📝 <b>Shablon " . ($index + 1) . "</b>", '', $tpl]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ Tahrirlash', 'callback_data' => "tpl_edit:{$index}"],
                    ['text' => '🗑 O\'chirish',  'callback_data' => "tpl_del:{$index}"],
                ],
                [['text' => '◀️ Orqaga', 'callback_data' => 'tpl_list']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function requestTemplateText(string $chatId, int $messageId, string $pendingKey): void
    {
        $this->stateService->setPending($pendingKey);

        $isEdit  = str_starts_with($pendingKey, 'tpl_edit:');
        $heading = $isEdit ? '✏️ <b>Yangi matnni yuboring</b>' : '✏️ <b>Shablon matnini yuboring</b>';

        $text = implode("\n", [
            $heading,
            '',
            'HTML formatlash qo\'llab-quvvatlanadi:',
            '<code>&lt;b&gt;qalin&lt;/b&gt;</code> · <code>&lt;i&gt;kursiv&lt;/i&gt;</code>',
            '',
            'Yangi qator uchun Enter bosing.',
        ]);

        $keyboard = ['inline_keyboard' => [
            [['text' => '❌ Bekor qilish', 'callback_data' => 'tpl_list']],
        ]];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function deleteTemplate(string $chatId, int $messageId, int $index): void
    {
        $this->templateService->delete($index);
        $this->showTemplateList($chatId, $messageId);
    }

    private function handlePendingInput(string $chatId, string $text, string $pending): void
    {
        $this->stateService->setPending(null);

        if ($pending === 'tpl_add') {
            $this->templateService->add($text);
            $this->sender->send($chatId, '✅ Shablon qo\'shildi!');
            $this->sendTemplateList($chatId);
        } elseif (str_starts_with($pending, 'tpl_edit:')) {
            $index = (int) substr($pending, 9);
            $this->templateService->update($index, $text);
            $this->sender->send($chatId, '✅ Shablon yangilandi!');
            $this->sendTemplateList($chatId);
        } elseif (str_starts_with($pending, 'grp_rename:')) {
            $index  = (int) substr($pending, 11);
            $groups = $this->stateService->getGroups();
            if (isset($groups[$index])) {
                $this->stateService->renameGroup($groups[$index]['id'], $text);
                $this->sender->send($chatId, "✅ Guruh nomi yangilandi: <b>{$text}</b>");
            }
            $this->sendGroupList($chatId);
        }
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
        $state         = $this->stateService->getState();
        $isActive      = (bool) ($state['is_active'] ?? false);
        $groups        = $state['groups'] ?? [];
        $activeIds     = $state['active_group_ids'] ?? [];
        $lastSent      = $state['last_sent_at'] ?? null;
        $interval      = max(5, (int) ($state['interval'] ?? 30));
        $templateCount = $this->templateService->count();
        $nextIndex     = (int) ($state['last_template_index'] ?? 0);
        $groupCount    = count($groups);
        $activeCount   = count($activeIds);

        $statusLine = $isActive ? '🟢 Faol' : '🔴 To\'xtatilgan';

        if ($isActive && $activeCount > 0) {
            $names = [];
            foreach ($activeIds as $id) {
                foreach ($groups as $g) {
                    if ($g['id'] === $id) {
                        $names[] = $g['title'] ?: $id;
                        break;
                    }
                }
            }
            $nameStr   = implode(', ', array_slice($names, 0, 2));
            if ($activeCount > 2) {
                $nameStr .= ' +' . ($activeCount - 2) . ' ta';
            }
            $groupLine = "📍 Faol guruhlar: {$nameStr}";
        } elseif ($groupCount > 0) {
            $groupLine = "📍 Guruhlar: {$groupCount} ta ulangan";
        } else {
            $groupLine = "📍 Guruhlar: ❌ Hali ulanmagan";
        }

        $lastSentLine = $lastSent
            ? Carbon::parse($lastSent)->setTimezone('Asia/Tashkent')->format('d.m.Y H:i:s')
            : 'Hali yuborilmagan';

        $tplDisplay = $templateCount > 0
            ? '#' . (($nextIndex % $templateCount) + 1) . " ({$templateCount} ta dan)"
            : '—';

        $text = implode("\n", [
            '🤖 <b>Bot Boshqaruv Paneli</b>',
            '',
            "📊 Holat: {$statusLine}",
            $groupLine,
            "⏱ Interval: " . $this->formatInterval($interval),
            "📝 Shablon: {$tplDisplay}",
            "🕐 Oxirgi yuborish: {$lastSentLine}",
        ]);

        $actionButton = $isActive
            ? ['text' => '🛑 To\'xtatish', 'callback_data' => 'stop']
            : ['text' => '▶️ Ishga tushirish', 'callback_data' => 'start'];

        $keyboard = [
            'inline_keyboard' => [
                [$actionButton],
                [
                    ['text' => "📋 Guruhlar ({$groupCount})",    'callback_data' => 'grp_list'],
                    ['text' => "📋 Shablonlar ({$templateCount})", 'callback_data' => 'tpl_list'],
                ],
                [['text' => '🔄 Yangilash', 'callback_data' => 'refresh']],
            ],
        ];

        return [$text, $keyboard];
    }

    private function formatInterval(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} sekund";
        }

        return intdiv($seconds, 60) . ' daqiqa';
    }

    // ── Telegram membership events ────────────────────────────────────────────

    private function handleMyChatMember(array $myChatMember): void
    {
        $chat      = $myChatMember['chat'] ?? [];
        $chatType  = $chat['type'] ?? '';
        $newStatus = $myChatMember['new_chat_member']['status'] ?? '';

        if (
            in_array($chatType, ['group', 'supergroup'], true) &&
            in_array($newStatus, ['member', 'administrator'], true)
        ) {
            $id    = (string) $chat['id'];
            $title = $chat['title'] ?? '';
            $isNew = !$this->stateService->hasGroup($id);
            $this->stateService->addGroup($id, $title);

            if ($isNew) {
                $adminId = (int) env('TELEGRAM_ADMIN_ID');
                $this->sender->send(
                    (string) $adminId,
                    "✅ Yangi guruh ulandi: <b>{$title}</b>\n🆔 <code>{$id}</code>"
                );
            }
        }
    }

    private function handleNewChatMembers(array $message): void
    {
        $chat     = $message['chat'] ?? [];
        $chatType = $chat['type'] ?? '';

        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }

        $botId    = (int) explode(':', config('telegram.bots.mybot.token'))[0];
        $newUsers = $message['new_chat_members'] ?? [];

        foreach ($newUsers as $user) {
            if ((int) $user['id'] === $botId) {
                $id    = (string) $chat['id'];
                $title = $chat['title'] ?? '';
                $isNew = !$this->stateService->hasGroup($id);
                $this->stateService->addGroup($id, $title);

                if ($isNew) {
                    $adminId = (int) env('TELEGRAM_ADMIN_ID');
                    $this->sender->send(
                        (string) $adminId,
                        "✅ Yangi guruh ulandi: <b>{$title}</b>\n🆔 <code>{$id}</code>"
                    );
                }

                break;
            }
        }
    }
}
