<?php

namespace App\Services\Bot;

use App\Models\BotGroup;
use App\Models\DriverBot;
use App\Models\Template;

class DriverBotService
{
    // ── Groups ────────────────────────────────────────────────────────────────

    public function addGroup(DriverBot $bot, string $chatId, string $title, ?string $username = null): void
    {
        $group = $bot->groups()->where('group_chat_id', $chatId)->first();

        if ($group) {
            $updates = [];
            if ($title && $group->title === '') {
                $updates['title'] = $title;
            }
            if ($username && !$group->username) {
                $updates['username'] = $username;
            }
            if ($updates) {
                $group->update($updates);
            }
            return;
        }

        $bot->groups()->create([
            'group_chat_id'  => $chatId,
            'title'          => $title,
            'username'       => $username,
            'run_selected'   => false,
            'wizard_selected' => false,
        ]);
    }

    public function removeGroup(DriverBot $bot, string $chatId): void
    {
        $bot->groups()->where('group_chat_id', $chatId)->delete();
    }

    public function renameGroup(DriverBot $bot, string $chatId, string $title): void
    {
        $bot->groups()->where('group_chat_id', $chatId)->update(['title' => $title]);
    }

    public function hasGroup(DriverBot $bot, string $chatId): bool
    {
        return $bot->groups()->where('group_chat_id', $chatId)->exists();
    }

    public function toggleWizardGroup(DriverBot $bot, int $groupId): void
    {
        $group = $bot->groups()->find($groupId);
        if ($group) {
            $group->update(['wizard_selected' => !$group->wizard_selected]);
        }
    }

    public function setWizardAllGroups(DriverBot $bot, bool $selected): void
    {
        $bot->groups()->update(['wizard_selected' => $selected]);
    }

    // ── Leave selection ──────────────────────────────────────────────────────

    public function toggleLeaveGroup(DriverBot $bot, int $groupId): void
    {
        $group = $bot->groups()->find($groupId);
        if ($group) {
            $group->update(['leave_selected' => !$group->leave_selected]);
        }
    }

    public function setLeaveAllGroups(DriverBot $bot, bool $selected): void
    {
        $bot->groups()->update(['leave_selected' => $selected]);
    }

    public function clearLeaveSelection(DriverBot $bot): void
    {
        $bot->groups()->update(['leave_selected' => false]);
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    public function addTemplate(DriverBot $bot, string $text): Template
    {
        $maxOrder = $bot->templates()->max('sort_order') ?? 0;

        return $bot->templates()->create([
            'body'       => $text,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    public function updateTemplate(Template $template, string $text): void
    {
        $template->update(['body' => $text]);
    }

    public function deleteTemplate(DriverBot $bot, Template $template): void
    {
        $isCurrent = $bot->current_template_id === $template->id;
        $isWizard  = $bot->wizard_template_id === $template->id;

        $template->delete();

        $updates = [];
        if ($isCurrent) {
            $updates['current_template_id'] = null;
            $updates['is_active'] = false;
        }
        if ($isWizard) {
            $updates['wizard_template_id'] = null;
        }
        if ($updates) {
            $bot->update($updates);
        }
    }

    // ── Wizard ────────────────────────────────────────────────────────────────

    public function setWizardTemplate(DriverBot $bot, int $templateId): void
    {
        $bot->update(['wizard_template_id' => $templateId]);
    }

    public function setWizardInterval(DriverBot $bot, int $interval): void
    {
        $bot->update(['wizard_interval' => max(5, $interval)]);
    }

    public function initWizardGroups(DriverBot $bot): void
    {
        $bot->groups()->update(['wizard_selected' => true]);
    }

    // ── Bot control ───────────────────────────────────────────────────────────

    public function startBot(DriverBot $bot): bool
    {
        $wizardGroups = $bot->groups()->where('wizard_selected', true)->get();

        if ($wizardGroups->isEmpty() || !$bot->wizard_template_id) {
            return false;
        }

        // Activate only wizard-selected groups
        $bot->groups()->update(['run_selected' => false]);
        $bot->groups()->where('wizard_selected', true)->update(['run_selected' => true]);
        $bot->groups()->update(['wizard_selected' => false]);

        $bot->update([
            'is_active'          => true,
            'current_template_id' => $bot->wizard_template_id,
            'interval'           => max(5, $bot->wizard_interval ?? 30),
            'last_sent_at'       => null,
            'pending'            => null,
            'wizard_template_id' => null,
            'wizard_interval'    => null,
        ]);

        // Close any stray open session, then open a new one
        $bot->sessions()->whereNull('stopped_at')->update(['stopped_at' => now()]);
        $bot->sessions()->create(['started_at' => now()]);
        $this->pruneSessions($bot);

        return true;
    }

    public function stopBot(DriverBot $bot): void
    {
        $bot->update(['is_active' => false]);
        $bot->sessions()->whereNull('stopped_at')->update(['stopped_at' => now()]);
    }

    private function pruneSessions(DriverBot $bot): void
    {
        // Keep only the latest 5 sessions per bot
        $keepIds = $bot->sessions()->orderByDesc('started_at')->limit(5)->pluck('id');
        $bot->sessions()->whereNotIn('id', $keepIds)->delete();
    }

    // ── Pending ───────────────────────────────────────────────────────────────

    public function setPending(DriverBot $bot, ?string $pending): void
    {
        $bot->update(['pending' => $pending]);
    }
}
