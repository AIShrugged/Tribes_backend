<?php

namespace App\Models;

use App\Enums\ChatRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class ChannelMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'author_identity_id',
        'legacy_source_type',
        'legacy_source_id',
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
        'agent_batch_uuid',
        'coalesced_at',
        'responded_at',
        'metadata',
    ];

    protected $casts = [
        'followup_data' => 'array',
        'metadata' => 'array',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'coalesced_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class, 'conversation_id');
    }

    public function authorIdentity(): BelongsTo
    {
        return $this->belongsTo(ChannelIdentity::class, 'author_identity_id');
    }

    public function issues(): MorphMany
    {
        return $this->morphMany(Issue::class, 'sourceable');
    }

    public function tasks(): MorphMany
    {
        return $this->issues();
    }

    public function getChatIdAttribute(): ?int
    {
        return $this->conversation?->chat_id;
    }

    public function getTelegramChatIdAttribute(): ?int
    {
        return $this->conversation?->telegram_chat_id;
    }

    public function statusValue(): string
    {
        return $this->statusEnum()->value;
    }

    public function statusEnum(): ChatRunStatus
    {
        $status = $this->getRawOriginal('status') ?: ChatRunStatus::COMPLETED->value;

        return ChatRunStatus::from((string) $status);
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
                'Invalid channel message transition: %s -> %s',
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
