<?php

namespace App\Services\Agent\Tools;

use App\Models\Profile;
use App\Services\Agent\MemoryService;
use App\Services\UserFocusService;

class ClearUserFocusTool implements ToolInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly UserFocusService $userFocusService,
        private readonly MemoryService $memoryService,
        private readonly string $channel = 'web',
    ) {}

    public function getName(): string
    {
        return 'clear_user_focus';
    }

    public function getDescription(): string
    {
        return 'Remove the user\'s active focus. '
             . 'Call when user says "clear my focus", "I\'m done with that sprint", '
             . '"remove my priority", "delete my focus", or similar. '
             . 'Do not call unless explicitly requested.';
    }

    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(?array $parameters): mixed
    {
        try {
            $this->userFocusService->clearFocus($this->profile);
            $this->memoryService->invalidateMemoryCache($this->profile, $this->channel);

            return ['success' => true, 'message' => 'Focus cleared.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}