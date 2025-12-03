<?php

namespace App\Services\Followup;

use App\Enums\FollowupScope;
use App\Enums\FollowupType;
use App\Services\Followup\Prompts\SharedStayfittV1Prompt;
use InvalidArgumentException;

class FollowupPromptRegistry
{
    /**
     * @var array<string, array<int, class-string<FollowupPromptInterface>>>
     */
    private array $map = [
        FollowupScope::SHARED->value   => [
            FollowupType::STAYFITT_V1->value => SharedStayfittV1Prompt::class,
        ],
        FollowupScope::PERSONAL->value => [
        ],
    ];

    public function resolve(string $scope, string $type): FollowupPromptInterface
    {
        if (!isset($this->map[$scope][$type])) {
            throw new InvalidArgumentException("Unknown followup prompt for scope={$scope}, version={$type}");
        }

        $class = $this->map[$scope][$type];

        return app($class);
    }
}
