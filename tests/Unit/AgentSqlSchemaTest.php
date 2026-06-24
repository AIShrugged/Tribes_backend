<?php

namespace Tests\Unit;

use App\Services\Agent\Tools\ExecuteSqlQueryTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AgentSqlSchemaTest extends TestCase
{
    #[Test]
    public function it_exposes_issues_not_meeting_tasks_in_the_agent_sql_whitelist(): void
    {
        $config = require __DIR__ . '/../../config/agent.php';

        $allowedTables = $config['sql_allowed_tables'];

        $this->assertContains('issues', $allowedTables);
        $this->assertNotContains('meeting_tasks', $allowedTables);
    }

    #[Test]
    public function it_does_not_expose_pii_insight_tables_to_raw_sql(): void
    {
        $config = require __DIR__ . '/../../config/agent.php';

        $allowedTables = $config['sql_allowed_tables'];

        // Psychological profiles / personal insights are read only via dedicated
        // tenant-scoped tools, never via free-form SQL.
        $this->assertNotContains('insight_profiles', $allowedTables);
        $this->assertNotContains('insight_items', $allowedTables);
        $this->assertNotContains('insight_profile_history', $allowedTables);
        $this->assertNotContains('insight_relationships', $allowedTables);
        $this->assertNotContains('insight_short_term', $allowedTables);
        $this->assertNotContains('insight_sources', $allowedTables);
    }

    #[Test]
    public function execute_sql_query_tool_points_agents_to_issues(): void
    {
        $tool = new ExecuteSqlQueryTool(1);

        $description = $tool->getDescription();
        $parameters = $tool->getParameters();

        $this->assertStringContainsString('issues', $description);
        $this->assertStringNotContainsString('meeting_tasks', $description);
        $this->assertStringContainsString('issues', $parameters['properties']['sql']['description']);
        $this->assertStringNotContainsString('meeting_tasks', $parameters['properties']['sql']['description']);
    }
}
