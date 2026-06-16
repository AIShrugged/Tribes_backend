<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AgentProfile;
use App\Services\Agent\AgentToolRegistrar;
use App\Services\Agent\Tools\ToolRegistry;
use Illuminate\Http\Request;

class AgentToolController extends Controller
{
    public function __construct(
        private readonly AgentToolRegistrar $toolRegistrar,
    ) {}

    public function index(Request $request): ApiResponse
    {
        $isMemberOfAnyOrganization = $request->user()
            ->organizations()
            ->wherePivot('role', 'manager')
            ->exists();

        if (! $isMemberOfAnyOrganization) {
            throw new AppException(
                'Only organization managers can manage agent tools.',
                'AGENT_TOOL_MANAGER_REQUIRED',
                403,
            );
        }

        $registry = new ToolRegistry;
        $this->toolRegistrar->registerDefaults($registry, $request->user(), 'web');

        $tools = collect($registry->getAll())
            ->map(fn ($tool): array => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParameters(),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return ApiResponse::success(data: $tools);
    }

    public function profileIndex(Request $request, AgentProfile $agentProfile): ApiResponse
    {
        $isMemberOfAnyOrganization = $request->user()
            ->organizations()
            ->exists();

        if (! $isMemberOfAnyOrganization) {
            throw new AppException(
                'Only organization members can manage agent tools.',
                'AGENT_TOOL_MANAGER_REQUIRED',
                403,
            );
        }

        $registry = new ToolRegistry;
        $this->toolRegistrar->registerDefaults($registry, $request->user(), 'web');

        $allowedNames = $this->normalizeAllowedToolNames($agentProfile->allowed_tools);

        $tools = collect($registry->getAll())
            ->when(
                ! empty($allowedNames),
                fn ($col) => $col->filter(
                    fn ($tool) => in_array($tool->getName(), $allowedNames, true)
                )
            )
            ->map(fn ($tool): array => [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters'  => $tool->getParameters(),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return ApiResponse::success(data: $tools);
    }

    /**
     * Historical/profile seed data may contain JSON scalar values in allowed_tools.
     */
    private function normalizeAllowedToolNames(mixed $allowedTools): array
    {
        if (is_array($allowedTools)) {
            return array_values(array_filter(
                $allowedTools,
                fn ($tool): bool => is_string($tool) && $tool !== '',
            ));
        }

        if (is_string($allowedTools)) {
            $trimmed = trim($allowedTools);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeAllowedToolNames($decoded);
            }

            return [$trimmed];
        }

        return [];
    }
}
