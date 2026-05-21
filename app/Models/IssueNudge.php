<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueNudge extends Model
{
    public const KIND_EXECUTOR = 'executor';
    public const KIND_MANAGER  = 'manager';

    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TEMPLATE_EXEC_1     = 'exec_1';
    public const TEMPLATE_EXEC_2     = 'exec_2';
    public const TEMPLATE_ESCALATION = 'escalation';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at'    => 'datetime',
            'attempt_no' => 'int',
            'days_stuck' => 'int',
            'payload'    => 'array',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
