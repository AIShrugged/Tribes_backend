<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
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
}
