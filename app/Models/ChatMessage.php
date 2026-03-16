<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ChatMessage extends Model
{
    protected $fillable = [
        'chat_id',
        'role',
        'status',
        'content',
        'followup_data',
        'error_message',
        'agent_run_uuid',
        'completed_at',
    ];

    protected $casts = [
        'followup_data' => 'array',
        'completed_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing' || $this->status === 'queued';
    }

    public function hasFollowup(): bool
    {
        return ! empty($this->followup_data);
    }
}
