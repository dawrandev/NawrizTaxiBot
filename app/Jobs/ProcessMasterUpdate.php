<?php

namespace App\Jobs;

use App\Http\Controllers\MasterBotWebhookController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes one Telegram update for the MASTER bot off the web request.
 *
 * The webhook controller only dispatches this job and returns 200 instantly,
 * so the PHP-FPM worker is never blocked by slow Telegram API calls. This job
 * runs on the dedicated "master" queue (its own worker) so admin panel actions
 * never wait behind driver broadcasts.
 */
class ProcessMasterUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;   // Telegram already retries delivery; don't double-send
    public int $timeout = 60;

    public function __construct(public array $update) {}

    public function handle(): void
    {
        app(MasterBotWebhookController::class)->process($this->update);
    }
}
