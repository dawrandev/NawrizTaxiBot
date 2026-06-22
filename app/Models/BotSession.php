<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSession extends Model
{
    protected $fillable = [
        'driver_bot_id',
        'started_at',
        'stopped_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function driverBot(): BelongsTo
    {
        return $this->belongsTo(DriverBot::class);
    }
}
