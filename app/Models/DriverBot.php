<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverBot extends Model
{
    protected $fillable = [
        'name',
        'chat_id',
        'bot_token',
        'bot_username',
        'is_active',
        'current_template_id',
        'wizard_template_id',
        'interval',
        'wizard_interval',
        'last_sent_at',
        'pending',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    public function groups(): HasMany
    {
        return $this->hasMany(BotGroup::class);
    }

    public function activeGroups(): HasMany
    {
        return $this->hasMany(BotGroup::class)->where('run_selected', true);
    }

    public function wizardGroups(): HasMany
    {
        return $this->hasMany(BotGroup::class)->where('wizard_selected', true);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class)->orderBy('sort_order')->orderBy('id');
    }

    public function currentTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'current_template_id');
    }

    public function wizardTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'wizard_template_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BotSession::class);
    }
}
