<?php

namespace App\Services\Bot;

use App\Models\DriverBot;
use App\Services\Telegram\TelegramClientFactory;
use Illuminate\Support\Collection;

class MasterBotService
{
    private string $statePath;

    public function __construct(private readonly TelegramClientFactory $factory)
    {
        $this->statePath = storage_path('master_state.json');
    }

    // ── Driver registration ───────────────────────────────────────────────────

    /**
     * Validate token via getMe and return bot info, or throw on failure.
     *
     * @return array{id: int, username: string, first_name: string}
     */
    public function validateToken(string $token): array
    {
        $sender = $this->factory->make($token);
        $me     = $sender->getApi()->getMe();

        return [
            'id'         => $me->getId(),
            'username'   => $me->getUsername() ?? '',
            'first_name' => $me->getFirstName() ?? '',
        ];
    }

    public function registerDriver(string $name, string $chatId, string $token, string $botUsername): DriverBot
    {
        return DriverBot::create([
            'name'         => $name,
            'chat_id'      => $chatId,
            'bot_token'    => $token,
            'bot_username' => $botUsername,
            'interval'     => 30,
        ]);
    }

    public function removeDriver(DriverBot $bot): void
    {
        $bot->delete();
    }

    public function getAllDrivers(): Collection
    {
        return DriverBot::orderBy('name')->get();
    }

    // ── Master wizard state (JSON, one admin) ─────────────────────────────────

    public function getState(): array
    {
        if (!file_exists($this->statePath)) {
            return $this->defaultState();
        }

        $data = json_decode(file_get_contents($this->statePath), true);

        return is_array($data) ? $data : $this->defaultState();
    }

    public function setState(array $state): void
    {
        file_put_contents(
            $this->statePath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function setPending(?string $pending): void
    {
        $state            = $this->getState();
        $state['pending'] = $pending;
        $this->setState($state);
    }

    public function getPending(): ?string
    {
        return $this->getState()['pending'] ?? null;
    }

    public function setWizardField(string $field, mixed $value): void
    {
        $state          = $this->getState();
        $state[$field]  = $value;
        $this->setState($state);
    }

    public function clearWizard(): void
    {
        $this->setState($this->defaultState());
    }

    private function defaultState(): array
    {
        return [
            'pending'        => null,
            'wizard_name'    => null,
            'wizard_chat_id' => null,
        ];
    }
}
