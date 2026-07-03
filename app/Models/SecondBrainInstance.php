<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Per-organization "second brain" desired state + secrets. The dedicated
 * orchestrator (brain:reconcile) converges Docker to this table: one Claude Code
 * container per enabled org, each with its own name, volume and TRIBESMCP token.
 *
 * The MCP bearer and the org's Claude credential are stored encrypted at rest
 * (`encrypted` cast under APP_KEY) and hidden from serialization — a manager
 * never receives them; only the container does.
 */
class SecondBrainInstance extends Model
{
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPING = 'stopping';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_ERROR = 'error';

    /** How the org authenticates to Claude inside its container. */
    public const AUTH_OAUTH = 'oauth';       // CLAUDE_CODE_OAUTH_TOKEN (subscription)
    public const AUTH_API_KEY = 'api_key';   // ANTHROPIC_API_KEY

    public const AUTH_TYPES = [self::AUTH_OAUTH, self::AUTH_API_KEY];

    protected $guarded = [];

    /** Secrets must never leak through toArray()/JSON. */
    protected $hidden = ['token_ciphertext', 'claude_auth_ciphertext'];

    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'claude_auth_ciphertext' => 'encrypted',
            'enabled' => 'boolean',
            'last_started_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'credentials_changed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_user_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    /** Single source of truth for the per-org container name. */
    public static function containerNameFor(int $organizationId): string
    {
        return "second-brain-org{$organizationId}";
    }

    /** Single source of truth for the per-org state volume name. */
    public static function volumeNameFor(int $organizationId): string
    {
        return "second-brain-state-org{$organizationId}";
    }
}
