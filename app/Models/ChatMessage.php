<?php

namespace App\Models;

use App\Enums\ChatRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class ChatMessage extends Model
{
    protected $fillable = [
        'chat_id',
        'role',
        'status',
        'content',
        'followup_data',
        'error_message',
        'failure_code',
        'agent_run_uuid',
        'current_attempt',
        'max_attempts',
        'completed_at',
        'next_retry_at',
    ];

    protected $casts = [
        'status' => ChatRunStatus::class,
        'followup_data' => 'array',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
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
        return in_array($this->statusEnum(), [
            ChatRunStatus::QUEUED,
            ChatRunStatus::PROCESSING,
            ChatRunStatus::RETRYING,
        ], true);
    }

    public function hasFollowup(): bool
    {
        return ! empty($this->followup_data);
    }

    public function statusValue(): ?string
    {
        return $this->statusEnum()->value;
    }

    public function statusEnum(): ChatRunStatus
    {
        if ($this->status instanceof ChatRunStatus) {
            return $this->status;
        }

        return ChatRunStatus::from((string) $this->status);
    }

    public function canTransitionTo(ChatRunStatus $target): bool
    {
        return $this->statusEnum()->canTransitionTo($target);
    }

    public function transitionTo(ChatRunStatus $target, array $attributes = []): void
    {
        $current = $this->statusEnum();

        if (! $current->canTransitionTo($target)) {
            throw new LogicException(sprintf(
                'Invalid chat run transition: %s -> %s',
                $current->value,
                $target->value,
            ));
        }

        $this->update([
            'status' => $target->value,
            ...$attributes,
        ]);
    }

    public function markProcessing(array $attributes = []): void
    {
        $this->transitionTo(ChatRunStatus::PROCESSING, $attributes);
    }

    public function markRetrying(array $attributes = []): void
    {
        $this->transitionTo(ChatRunStatus::RETRYING, $attributes);
    }

    public function markCompleted(array $attributes = []): void
    {
        $this->transitionTo(ChatRunStatus::COMPLETED, $attributes);
    }

    public function markFailed(array $attributes = []): void
    {
        $this->transitionTo(ChatRunStatus::FAILED, $attributes);
    }
}
