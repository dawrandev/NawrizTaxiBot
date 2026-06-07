<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotGroup extends Model
{
    protected $fillable = [
        'driver_bot_id',
        'group_chat_id',
        'title',
        'username',
        'run_selected',
        'wizard_selected',
        'leave_selected',
    ];

    protected $casts = [
        'run_selected'    => 'boolean',
        'wizard_selected' => 'boolean',
        'leave_selected'  => 'boolean',
    ];

    public function driverBot(): BelongsTo
    {
        return $this->belongsTo(DriverBot::class);
    }

    public function displayTitle(): string
    {
        return $this->title ?: $this->group_chat_id;
    }
}
