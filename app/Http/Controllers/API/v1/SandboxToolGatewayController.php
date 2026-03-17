<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AgentTaskRun;
use App\Services\SandboxLlmGatewayService;
use App\Services\SandboxToolGatewayService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;

#[Group('Sandbox Gateway', 'Internal host-mediated tool execution for isolated sandbox runs.')]
class SandboxToolGatewayController extends Controller
{
    #[Endpoint(title: 'Execute sandbox tool call', description: 'Internal endpoint used by isolated sandbox runs to invoke allowlisted host tools using a short-lived run token.')]
    #[PathParameter('run', 'Agent task run ID.', required: true, type: 'integer', example: 15)]
    #[HeaderParameter('X-Sandbox-Run-Token', 'Short-lived sandbox run token.', required: true, type: 'string', example: 'sandbox_run_token_example')]
    #[BodyParameter('tool_name', 'Allowlisted tool name to execute on the host.', required: true, type: 'string', example: 'search_memory')]
    #[BodyParameter('arguments', 'Tool arguments.', required: false, type: 'object', example: ['scope' => 'repository', 'query' => 'architecture'])]
    #[Response(
        200,
        'Successful tool execution envelope.',
        type: 'array{success: bool, data: array<string, mixed>, message: string, status: int, meta: array<string, mixed>}'
    )]
    #[Response(
        401,
        'Missing or invalid sandbox token.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    #[Response(
        403,
        'Tool call denied by allowlist or policy.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function store(Request $request, AgentTaskRun $run, SandboxToolGatewayService $gateway): ApiResponse
    {
        $validated = $request->validate([
            'tool_name' => ['required', 'string'],
            'arguments' => ['nullable', 'array'],
        ]);

        $plainToken = (string) $request->header('X-Sandbox-Run-Token', '');
        if ($plainToken === '') {
            return ApiResponse::error('Missing sandbox run token', status: 401);
        }

        try {
            $result = $gateway->executeToolCall(
                $run,
                $plainToken,
                $validated['tool_name'],
                $validated['arguments'] ?? [],
            );
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'token') ? 401 : 403;

            return ApiResponse::error($e->getMessage(), status: $status);
        }

        return ApiResponse::success(data: $result);
    }

    #[Endpoint(title: 'Execute sandbox LLM completion', description: 'Internal endpoint used by isolated sandbox runs to request an LLM turn with the run-scoped model and allowlisted tools.')]
    #[PathParameter('run', 'Agent task run ID.', required: true, type: 'integer', example: 15)]
    #[HeaderParameter('X-Sandbox-Run-Token', 'Short-lived sandbox run token.', required: true, type: 'string', example: 'sandbox_run_token_example')]
    #[BodyParameter('messages', 'Conversation messages in OpenAI format.', required: true, type: 'array', example: [['role' => 'user', 'content' => 'Inspect the repository and summarize architecture.']])]
    #[BodyParameter('system_prompt', 'Optional system prompt string.', required: false, type: 'string')]
    #[BodyParameter('max_tokens', 'Optional max completion tokens.', required: false, type: 'integer', example: 2048)]
    #[BodyParameter('include_tools', 'Whether allowlisted tools should be exposed for this completion. Set false for normalization/extraction passes.', required: false, type: 'bool', example: true)]
    #[Response(
        200,
        'Successful LLM completion envelope.',
        type: 'array{success: bool, data: array<string, mixed>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function complete(Request $request, AgentTaskRun $run, SandboxLlmGatewayService $gateway): ApiResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'system_prompt' => ['nullable', 'string'],
            'max_tokens' => ['nullable', 'integer', 'min:256', 'max:4096'],
            'include_tools' => ['nullable', 'boolean'],
        ]);

        $plainToken = (string) $request->header('X-Sandbox-Run-Token', '');
        if ($plainToken === '') {
            return ApiResponse::error('Missing sandbox run token', status: 401);
        }

        try {
            $result = $gateway->executeCompletion(
                $run,
                $plainToken,
                $validated['messages'],
                $validated['system_prompt'] ?? null,
                $validated['max_tokens'] ?? null,
                $request->boolean('include_tools', true),
            );
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'token') ? 401 : 403;

            return ApiResponse::error($e->getMessage(), status: $status);
        }

        return ApiResponse::success(data: $result);
    }
}
