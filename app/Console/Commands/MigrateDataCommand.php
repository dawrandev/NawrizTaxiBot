<?php

namespace App\Console\Commands;

use App\Models\DriverBot;
use Illuminate\Console\Command;

class MigrateDataCommand extends Command
{
    protected $signature   = 'bot:migrate-data';
    protected $description = 'Migrate existing JSON state and templates to the database';

    public function handle(): int
    {
        $token = env('TELEGRAM_BOT_TOKEN');

        if (!$token) {
            $this->error('TELEGRAM_BOT_TOKEN .env da topilmadi.');
            return 1;
        }

        if (DriverBot::where('bot_token', $token)->exists()) {
            $this->warn('Bu token allaqachon DB da mavjud. Migration o\'tkazib yuborildi.');
            return 0;
        }

        $chatId   = (string) env('TELEGRAM_ADMIN_ID', '');
        $interval = (int) env('TELEGRAM_INTERVAL', 30);

        // Create the first driver bot record
        $bot = DriverBot::create([
            'name'      => 'Birinchi driver',
            'chat_id'   => $chatId,
            'bot_token' => $token,
            'interval'  => $interval,
        ]);

        $this->info("✅ Driver bot yaratildi: ID = {$bot->id}");

        // Migrate templates from storage/templates.json
        $tplPath = storage_path('templates.json');

        if (file_exists($tplPath)) {
            $templates = json_decode(file_get_contents($tplPath), true) ?? [];
            foreach ($templates as $i => $body) {
                $bot->templates()->create(['body' => $body, 'sort_order' => $i]);
            }
            $this->info('✅ ' . count($templates) . ' ta shablon ko\'chirildi.');
        } else {
            $this->warn('storage/templates.json topilmadi, shablonlar ko\'chirilmadi.');
        }

        // Migrate groups from storage/bot_state.json
        $statePath = storage_path('bot_state.json');

        if (file_exists($statePath)) {
            $state  = json_decode(file_get_contents($statePath), true) ?? [];
            $groups = $state['groups'] ?? [];

            // Support old single group_chat_id format
            if (empty($groups) && !empty($state['group_chat_id'])) {
                $groups = [['id' => (string) $state['group_chat_id'], 'title' => '']];
            }

            foreach ($groups as $group) {
                $bot->groups()->create([
                    'group_chat_id' => $group['id'],
                    'title'         => $group['title'] ?? '',
                ]);
            }
            $this->info('✅ ' . count($groups) . ' ta guruh ko\'chirildi.');
        } else {
            $this->warn('storage/bot_state.json topilmadi, guruhlar ko\'chirilmadi.');
        }

        $this->line('');
        $this->info("Endi quyidagi buyruqni ishga tushiring:");
        $this->line("  php artisan bot:set-webhooks");

        return 0;
    }
}
