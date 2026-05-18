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
     * Read the current bot state from the state file.
     * Creates the file with defaults if it does not exist.
     */
    public function getState(): array
    {
        if (!file_exists($this->statePath)) {
            $default = $this->defaultState();
            $this->setState($default);

            return $default;
        }

        $data = json_decode(file_get_contents($this->statePath), true);

        return is_array($data) ? $data : $this->defaultState();
    }

    /**
     * Persist the given state array to the state file atomically.
     */
    public function setState(array $state): void
    {
        file_put_contents(
            $this->statePath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * Toggle the bot's active flag without changing other settings.
     */
    public function setActive(bool $active): void
    {
        $state = $this->getState();
        $state['is_active'] = $active;
        $this->setState($state);
    }

    /**
     * Return whether the bot is currently active.
     */
    public function isActive(): bool
    {
        return (bool) ($this->getState()['is_active'] ?? false);
    }

    /**
     * Persist the Telegram group chat ID where the bot is deployed.
     */
    public function setGroupChatId(string $chatId): void
    {
        $state = $this->getState();
        $state['group_chat_id'] = $chatId;
        $this->setState($state);
    }

    /**
     * Retrieve the saved Telegram group chat ID, or null if not yet registered.
     */
    public function getGroupChatId(): ?string
    {
        $value = $this->getState()['group_chat_id'] ?? null;

        return $value ? (string) $value : null;
    }

    /**
     * Return the current send interval in seconds (minimum 5).
     */
    public function getInterval(): int
    {
        return max(5, (int) ($this->getState()['interval'] ?? 30));
    }

    /**
     * Store a pending action key to wait for the admin's next text message.
     */
    public function setPending(?string $pending): void
    {
        $state = $this->getState();
        $state['pending'] = $pending;
        $this->setState($state);
    }

    /**
     * Retrieve the pending action key, or null if none.
     */
    public function getPending(): ?string
    {
        return $this->getState()['pending'] ?? null;
    }

    /**
     * Atomically apply wizard settings and activate the bot.
     */
    public function startBot(int $templateIndex, int $interval): void
    {
        $state                        = $this->getState();
        $state['is_active']           = true;
        $state['last_template_index'] = $templateIndex;
        $state['interval']            = max(5, $interval);
        $state['last_sent_at']        = null;
        $state['pending']             = null;
        $this->setState($state);
    }

    /**
     * Advance the template index and record the send timestamp.
     *
     * @param int $nextTemplateIndex Index of the template to send on the next cycle.
     */
    public function updateAfterSend(int $nextTemplateIndex): void
    {
        $state = $this->getState();
        $state['last_template_index'] = $nextTemplateIndex;
        $state['last_sent_at'] = Carbon::now()->toIso8601String();
        $this->setState($state);
    }

    private function defaultState(): array
    {
        return [
            'is_active'           => false,
            'last_template_index' => 0,
            'last_sent_at'        => null,
            'group_chat_id'       => null,
            'interval'            => (int) env('TELEGRAM_INTERVAL', 30),
            'pending'             => null,
        ];
    }
}
