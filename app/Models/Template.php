<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    protected $fillable = [
        'driver_bot_id',
        'body',
        'sort_order',
    ];

    public function driverBot(): BelongsTo
    {
        return $this->belongsTo(DriverBot::class);
    }
}
