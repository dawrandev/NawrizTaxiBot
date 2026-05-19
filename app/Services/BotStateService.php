<?php

namespace App\Services;

use Carbon\Carbon;

class BotStateService
{
    private string $statePath;

    public function __construct()
    {
        $this->statePath = storage_path('bot_state.json');
    }

    /**
     * Read state, creating defaults or migrating old format if needed.
     */
    public function getState(): array
    {
        if (!file_exists($this->statePath)) {
            $default = $this->defaultState();
            $this->setState($default);

            return $default;
        }

        $data = json_decode(file_get_contents($this->statePath), true);

        if (!is_array($data)) {
            return $this->defaultState();
        }

        // Migrate old single group_chat_id → groups array
        if (array_key_exists('group_chat_id', $data) && !array_key_exists('groups', $data)) {
            $data['groups'] = $data['group_chat_id']
                ? [['id' => (string) $data['group_chat_id'], 'title' => '']]
                : [];
            unset($data['group_chat_id']);
            $data['active_group_ids'] = [];
            $data['wizard_template']  = null;
            $data['wizard_interval']  = null;
            $data['wizard_group_ids'] = [];
            $this->setState($data);
        }

        return $data;
    }

    /**
     * Persist state atomically.
     */
    public function setState(array $state): void
    {
        file_put_contents(
            $this->statePath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    // ── Bot control ───────────────────────────────────────────────────────────

    public function setActive(bool $active): void
    {
        $state = $this->getState();
        $state['is_active'] = $active;
        $this->setState($state);
    }

    public function isActive(): bool
    {
        return (bool) ($this->getState()['is_active'] ?? false);
    }

    public function recordSend(): void
    {
        $state = $this->getState();
        $state['last_sent_at'] = Carbon::now()->toIso8601String();
        $this->setState($state);
    }

    /**
     * Apply wizard results and activate the bot.
     */
    public function startBot(int $templateIndex, int $interval, array $groupIds): void
    {
        $state                        = $this->getState();
        $state['is_active']           = true;
        $state['last_template_index'] = $templateIndex;
        $state['interval']            = max(5, $interval);
        $state['active_group_ids']    = array_values($groupIds);
        $state['last_sent_at']        = null;
        $state['pending']             = null;
        $state['wizard_template']     = null;
        $state['wizard_interval']     = null;
        $state['wizard_group_ids']    = [];
        $this->setState($state);
    }

    // ── Groups ────────────────────────────────────────────────────────────────

    public function getGroups(): array
    {
        return $this->getState()['groups'] ?? [];
    }

    public function hasGroup(string $id): bool
    {
        foreach ($this->getGroups() as $g) {
            if ($g['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add group if not already present; update title if it was empty.
     */
    public function addGroup(string $id, string $title): void
    {
        $state  = $this->getState();
        $groups = $state['groups'] ?? [];

        foreach ($groups as &$group) {
            if ($group['id'] === $id) {
                if ($title && empty($group['title'])) {
                    $group['title'] = $title;
                    $state['groups'] = $groups;
                    $this->setState($state);
                }

                return;
            }
        }

        $state['groups'][] = ['id' => $id, 'title' => $title];
        $this->setState($state);
    }

    public function renameGroup(string $id, string $title): void
    {
        $state  = $this->getState();
        $groups = $state['groups'] ?? [];

        foreach ($groups as &$group) {
            if ($group['id'] === $id) {
                $group['title'] = $title;
                break;
            }
        }

        $state['groups'] = $groups;
        $this->setState($state);
    }

    /**
     * Remove group and clean it from active/wizard selections.
     */
    public function removeGroup(string $id): void
    {
        $state = $this->getState();

        $state['groups'] = array_values(
            array_filter($state['groups'] ?? [], fn($g) => $g['id'] !== $id)
        );

        $state['active_group_ids'] = array_values(
            array_filter($state['active_group_ids'] ?? [], fn($gid) => $gid !== $id)
        );

        $state['wizard_group_ids'] = array_values(
            array_filter($state['wizard_group_ids'] ?? [], fn($gid) => $gid !== $id)
        );

        $this->setState($state);
    }

    public function getActiveGroupIds(): array
    {
        return $this->getState()['active_group_ids'] ?? [];
    }

    // ── Wizard ────────────────────────────────────────────────────────────────

    public function setWizardTemplate(int $index): void
    {
        $state = $this->getState();
        $state['wizard_template'] = $index;
        $this->setState($state);
    }

    public function setWizardInterval(int $interval): void
    {
        $state = $this->getState();
        $state['wizard_interval'] = max(5, $interval);
        $this->setState($state);
    }

    public function setWizardGroupIds(array $ids): void
    {
        $state = $this->getState();
        $state['wizard_group_ids'] = array_values($ids);
        $this->setState($state);
    }

    /**
     * Toggle group at the given index in the wizard selection.
     */
    public function toggleWizardGroup(int $groupIndex): void
    {
        $state  = $this->getState();
        $groups = $state['groups'] ?? [];

        if (!isset($groups[$groupIndex])) {
            return;
        }

        $id     = $groups[$groupIndex]['id'];
        $wizIds = $state['wizard_group_ids'] ?? [];

        if (in_array($id, $wizIds, true)) {
            $wizIds = array_values(array_filter($wizIds, fn($gid) => $gid !== $id));
        } else {
            $wizIds[] = $id;
        }

        $state['wizard_group_ids'] = $wizIds;
        $this->setState($state);
    }

    // ── Misc ──────────────────────────────────────────────────────────────────

    public function getInterval(): int
    {
        return max(5, (int) ($this->getState()['interval'] ?? 30));
    }

    public function setPending(?string $pending): void
    {
        $state = $this->getState();
        $state['pending'] = $pending;
        $this->setState($state);
    }

    public function getPending(): ?string
    {
        return $this->getState()['pending'] ?? null;
    }

    private function defaultState(): array
    {
        return [
            'is_active'           => false,
            'last_template_index' => 0,
            'last_sent_at'        => null,
            'groups'              => [],
            'active_group_ids'    => [],
            'interval'            => (int) env('TELEGRAM_INTERVAL', 30),
            'pending'             => null,
            'wizard_template'     => null,
            'wizard_interval'     => null,
            'wizard_group_ids'    => [],
        ];
    }
}
