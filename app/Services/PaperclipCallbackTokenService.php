<?php

namespace App\Services;

use App\Models\AgentTaskRun;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PaperclipCallbackTokenService
{
    public function issue(AgentTaskRun $run): string
    {
        $plainToken = Str::random(64);
        $expiresAt = now()->addSeconds((int) config('paperclip.callback.token_ttl_seconds', 86400));

        $run->update([
            'metadata' => array_merge($run->metadata ?? [], [
                'paperclip_callback_token' => [
                    'hash' => Hash::make($plainToken),
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]),
        ]);

        return $plainToken;
    }

    public function validate(AgentTaskRun $run, ?string $plainToken): bool
    {
        $tokenData = data_get($run->metadata, 'paperclip_callback_token');

        if (! is_array($tokenData) || $plainToken === null || $plainToken === '') {
            return false;
        }

        $hash = data_get($tokenData, 'hash');
        $expiresAt = data_get($tokenData, 'expires_at');

        if (! is_string($hash) || $hash === '' || ! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        if (now()->greaterThan(\Carbon\Carbon::parse($expiresAt))) {
            return false;
        }

        return Hash::check($plainToken, $hash);
    }
}
