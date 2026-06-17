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

    #[Test]
    public function code_changes_category_surfaces_the_read_only_git_tools(): void
    {
        config(['agent.tool_router.enabled' => true]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"categories": ["code_changes"]}'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $names = app(AgentToolRouter::class)->selectToolNames('what changed in the backend repo yesterday?');

        $this->assertIsArray($names);
        foreach ([
            'github_list_commits', 'github_get_commit', 'github_get_repository',
            'get_last_commit_report', 'get_issue_candidates', 'search_issues_by_text', 'get_issue_detail',
        ] as $tool) {
            $this->assertContains($tool, $names, "code_changes must surface {$tool}");
        }
        // The autonomous-write tools are in no category — never routed to interactive chat.
        $this->assertNotContains('save_commit_report', $names);
        $this->assertNotContains('update_commit_report_item', $names);
    }

    #[Test]
    public function autonomous_write_tools_belong_to_no_router_category(): void
    {
        // Even if the router selected EVERY category, the autonomous-write tools never appear,
        // because they map to no category. The router-null fallback is closed separately by
        // AgentService forgetting them on every interactive run.
        $categories = (new \ReflectionClass(AgentToolRouter::class))->getConstant('CATEGORIES');

        $allCategoryTools = [];
        foreach ($categories as $meta) {
            $allCategoryTools = array_merge($allCategoryTools, $meta['tools']);
        }

        $this->assertNotContains('save_commit_report', $allCategoryTools);
        $this->assertNotContains('update_commit_report_item', $allCategoryTools);
    }
}
