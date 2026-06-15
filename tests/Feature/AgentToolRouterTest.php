<?php

namespace Tests\Feature;

use App\Services\Agent\AgentToolRouter;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentToolRouterTest extends TestCase
{
    protected bool $mockLlm = false;

    #[Test]
    public function it_returns_null_when_disabled(): void
    {
        config(['agent.tool_router.enabled' => false]);

        $this->assertNull(app(AgentToolRouter::class)->selectToolNames('what are my tasks?'));
    }

    #[Test]
    public function it_returns_always_on_plus_selected_category_tools(): void
    {
        config(['agent.tool_router.enabled' => true]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"categories": ["github"]}'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $names = app(AgentToolRouter::class)->selectToolNames('open a pull request');

        $this->assertIsArray($names);
        // Always-on core is present.
        $this->assertContains('send_user_message', $names);
        $this->assertContains('query_db', $names);
        // Selected category tools are present.
        $this->assertContains('github_create_pull_request', $names);
        // Unselected category tools are absent.
        $this->assertNotContains('get_transcript', $names);
        $this->assertNotContains('list_workspaces', $names);
    }

    #[Test]
    public function it_falls_back_to_null_on_malformed_router_output(): void
    {
        config(['agent.tool_router.enabled' => true]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'not json at all'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $this->assertNull(app(AgentToolRouter::class)->selectToolNames('hello'));
    }
}
