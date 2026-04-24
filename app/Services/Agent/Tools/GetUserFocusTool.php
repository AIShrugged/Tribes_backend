<?php

namespace App\Services\Agent\Tools;

use App\Models\Profile;
use App\Services\UserFocusService;

class GetUserFocusTool implements ToolInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly UserFocusService $userFocusService,
    ) {}

    public function getName(): string
    {
        return 'get_user_focus';
    }

    public function getDescription(): string
    {
        return 'Retrieve the user\'s saved focus record with metadata (deadline, expiry, TTL remaining). '
             . 'NOTE: focus text is already in the "### Active Focus" section of your system prompt when active — '
             . 'do NOT call this during normal conversation. '
             . 'Call only when: (1) user asks about expiry date or TTL, '
             . '(2) you need to verify focus before overwriting it, '
             . '(3) you just set focus this session and need the refreshed value with timestamps.';
    }

    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(?array $parameters): mixed
    {
        $focus = $this->userFocusService->getFocus($this->profile);

        if (! $focus) {
            return ['success' => true, 'focus' => null, 'message' => 'No active focus set.'];
        }

        $ttlRemaining = (int) now()->diffInDays($focus->expires_at, false);

        return [
            'success' => true,
            'focus'   => [
                'text'          => $focus->content['focus_text'] ?? null,
                'deadline'      => $focus->content['deadline'] ?? null,
                'expires_at'    => $focus->expires_at?->toIso8601String(),
                'ttl_days_left' => max(0, $ttlRemaining),
            ],
        ];
    }
}