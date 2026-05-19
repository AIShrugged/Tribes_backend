<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAgentFlowPendingReply extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(IssueAgentFlow::class, 'issue_agent_flow_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    public function markConsumed(): void
    {
        $this->update(['consumed_at' => now()]);
    }
}
