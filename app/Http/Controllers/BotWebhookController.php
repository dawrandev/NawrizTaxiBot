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
            $this->stateService->setGroupChatId((string) $message['forward_from_chat']['id']);
            $this->sender->send($chatId, '✅ Guruh muvaffaqiyatli ulandi!');

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

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = (string) $callbackQuery['id'];
        $data       = $callbackQuery['data'] ?? '';
        $chatId     = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $messageId  = (int) ($callbackQuery['message']['message_id'] ?? 0);
        $fromId     = (int) ($callbackQuery['from']['id'] ?? 0);
        $adminId    = (int) env('TELEGRAM_ADMIN_ID');

        if ($fromId !== $adminId) {
            $this->sender->answerCallback($callbackId);

            return;
        }

        // Any button press cancels pending text input
        $this->stateService->setPending(null);
        $this->sender->answerCallback($callbackId);

        match (true) {
            $data === 'start'                   => $this->showTemplateStep($chatId, $messageId),
            $data === 'stop'                    => $this->handleStop($chatId, $messageId),
            $data === 'refresh'                 => $this->updatePanel($chatId, $messageId),
            $data === 'back'                    => $this->updatePanel($chatId, $messageId),
            $data === 'connect'                 => $this->showConnectGuide($chatId, $messageId),
            $data === 'tpl_list'                => $this->showTemplateList($chatId, $messageId),
            $data === 'tpl_add'                 => $this->requestTemplateText($chatId, $messageId, 'tpl_add'),
            str_starts_with($data, 'tpl:')      => $this->showIntervalStep($chatId, $messageId, (int) substr($data, 4)),
            str_starts_with($data, 'int:')      => $this->applyAndStart($chatId, $messageId, $data),
            str_starts_with($data, 'tpl_view:') => $this->showTemplateView($chatId, $messageId, (int) substr($data, 9)),
            str_starts_with($data, 'tpl_edit:') => $this->requestTemplateText($chatId, $messageId, $data),
            str_starts_with($data, 'tpl_del:')  => $this->deleteTemplate($chatId, $messageId, (int) substr($data, 8)),
            default                             => null,
        };
    }

    private function handleStop(string $chatId, int $messageId): void
    {
        $this->stateService->setActive(false);
        $this->updatePanel($chatId, $messageId);
    }

    private function handlePendingInput(string $chatId, string $text, string $pending): void
    {
        $this->stateService->setPending(null);

        if ($pending === 'tpl_add') {
            $this->templateService->add($text);
            $this->sender->send($chatId, '✅ Shablon qo\'shildi!');
        } elseif (str_starts_with($pending, 'tpl_edit:')) {
            $index = (int) substr($pending, 9);
            $this->templateService->update($index, $text);
            $this->sender->send($chatId, '✅ Shablon yangilandi!');
        }

        $this->sendTemplateList($chatId);
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

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ Bekor qilish', 'callback_data' => 'tpl_list']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function showConnectGuide(string $chatId, int $messageId): void
    {
        $text = implode("\n", [
            '📌 <b>Guruhni ulash</b>',
            '',
            '<b>1-usul (eng qulay):</b>',
            'Guruhdan istalgan xabarni shu botga <b>forward</b> qiling.',
            '',
            '<b>2-usul:</b>',
            'Botni guruhdan chiqaring va qayta qo\'shing.',
            'Bot avtomatik ulanadi.',
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '◀️ Orqaga', 'callback_data' => 'back']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    // ── Start wizard ──────────────────────────────────────────────────────────

    private function showTemplateStep(string $chatId, int $messageId): void
    {
        $templates = $this->templateService->all();
        $lines     = ['📝 <b>Qaysi shablon yuborilsin?</b>', ''];
        $buttons   = [];

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

        $mk = fn(string $label, int $secs) => ['text' => $label, 'callback_data' => "int:{$templateIndex}:{$secs}"];

        $keyboard = [
            'inline_keyboard' => [
                [$mk('5 sek', 5),  $mk('10 sek', 10), $mk('20 sek', 20)],
                [$mk('30 sek', 30), $mk('1 daqiqa', 60)],
                [['text' => '◀️ Orqaga', 'callback_data' => 'start']],
            ],
        ];

        $this->sender->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function applyAndStart(string $chatId, int $messageId, string $data): void
    {
        $parts         = explode(':', $data);
        $templateIndex = (int) ($parts[1] ?? 0);
        $interval      = (int) ($parts[2] ?? 30);

        $this->stateService->startBot($templateIndex, $interval);
        $this->updatePanel($chatId, $messageId);
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

        $text = implode("\n", [
            "📝 <b>Shablon " . ($index + 1) . "</b>",
            '',
            $tpl,
        ]);

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

    private function deleteTemplate(string $chatId, int $messageId, int $index): void
    {
        $this->templateService->delete($index);
        $this->showTemplateList($chatId, $messageId);
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
        $groupChatId   = $state['group_chat_id'] ?? null;
        $lastSent      = $state['last_sent_at'] ?? null;
        $interval      = max(5, (int) ($state['interval'] ?? 30));
        $templateCount = $this->templateService->count();
        $nextIndex     = (int) ($state['last_template_index'] ?? 0);

        $statusLine = $isActive ? '🟢 Faol' : '🔴 To\'xtatilgan';

        $groupLine = $groupChatId
            ? "✅ Ulangan (<code>{$groupChatId}</code>)"
            : '❌ Guruh ulanmagan';

        $lastSentLine = $lastSent
            ? Carbon::parse($lastSent)->setTimezone('Asia/Tashkent')->format('d.m.Y H:i:s')
            : 'Hali yuborilmagan';

        $text = implode("\n", [
            '🤖 <b>Bot Boshqaruv Paneli</b>',
            '',
            "📊 Holat: {$statusLine}",
            "📍 Guruh: {$groupLine}",
            "⏱ Interval: " . $this->formatInterval($interval),
            "📝 Navbatdagi shablon: " . ($templateCount > 0 ? ($nextIndex % $templateCount) + 1 : '—') . " / {$templateCount}",
            "🕐 Oxirgi yuborish: {$lastSentLine}",
        ]);

        $actionButton = $isActive
            ? ['text' => '🛑 To\'xtatish', 'callback_data' => 'stop']
            : ['text' => '▶️ Ishga tushirish', 'callback_data' => 'start'];

        $row2 = $groupChatId
            ? [['text' => '📋 Shablonlar', 'callback_data' => 'tpl_list'], ['text' => '🔄 Yangilash', 'callback_data' => 'refresh']]
            : [['text' => '📋 Shablonlar', 'callback_data' => 'tpl_list'], ['text' => '🔗 Guruhni ulash', 'callback_data' => 'connect']];

        $row3 = $groupChatId
            ? []
            : [['text' => '🔄 Yangilash', 'callback_data' => 'refresh']];

        $rows = array_filter([[$actionButton], $row2, $row3]);

        $keyboard = ['inline_keyboard' => array_values($rows)];

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

    /**
     * Fired when the bot's own membership status changes in a chat.
     */
    private function handleMyChatMember(array $myChatMember): void
    {
        $chat      = $myChatMember['chat'] ?? [];
        $chatType  = $chat['type'] ?? '';
        $newStatus = $myChatMember['new_chat_member']['status'] ?? '';

        if (
            in_array($chatType, ['group', 'supergroup'], true) &&
            in_array($newStatus, ['member', 'administrator'], true)
        ) {
            $this->stateService->setGroupChatId((string) $chat['id']);
        }
    }

    /**
     * Fallback for older Telegram clients that send new_chat_members service messages.
     */
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
                $this->stateService->setGroupChatId((string) $chat['id']);
                break;
            }
        }
    }
}
