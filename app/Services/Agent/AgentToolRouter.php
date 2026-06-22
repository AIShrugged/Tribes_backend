<?php

namespace App\Services\Agent;

use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

/**
 * Two-phase tool selection. Before the main agent loop, a cheap model picks
 * which tool categories the user's message needs, so only the relevant tool
 * schemas (plus an always-on core) are sent to the LLM — instead of all ~50 on
 * every iteration. Any failure returns null, signalling "keep the full toolset".
 */
class AgentToolRouter
{
    /**
     * Tools that are always available regardless of routing. Listing a name that
     * isn't registered for the current run is harmless (keepOnly ignores it).
     *
     * @var array<int, string>
     */
    private const ALWAYS_ON = [
        'query_db',
        'query_data',
        'describe_entity',
        'execute_sql_query',
        'get_organization_context',
        'get_user_info',
        'get_user_organizations',
        'send_user_message',
        'get_chat_history',
        'get_current_user',
        'create_artifact',
        'update_artifact',
        // Core task mutations: keep them available regardless of which category the
        // router guesses, so "reassign these to Ivan" / "close it" never falls through
        // to "I can only read" when the request doesn't read as tasks_issues. They are
        // authorized + audited + taint-gated, so always exposing them is safe.
        'set_task_status',
        'reassign_task',
        'update_task_fields',
    ];

    /**
     * Category key => human description (for the router prompt) and member tools.
     *
     * @var array<string, array{description: string, tools: array<int, string>}>
     */
    private const CATEGORIES = [
        'people_insights' => [
            'description' => 'Information about people: roles, profiles, insights, relationships, org membership, team members.',
            'tools' => ['get_user_info', 'get_user_insights', 'get_relationship_insight', 'get_insight_profile_history', 'get_user_organizations', 'get_team_members'],
        ],
        'tasks_issues' => [
            'description' => 'Tasks/issues: list, create, update, daily plan, critical path, digests, validations.',
            'tools' => ['get_open_issues', 'get_focused_issues', 'build_daily_plan', 'get_critical_path', 'get_daily_task_digest', 'get_weekly_task_digest', 'get_pending_issue_validations', 'answer_issue_validation', 'create_entity', 'update_entity', 'set_task_status', 'reassign_task', 'update_task_fields', 'get_meeting_tasks'],
        ],
        'meetings' => [
            'description' => 'Meetings: transcripts, agendas, meeting tasks.',
            'tools' => ['get_transcript', 'get_meeting_agenda', 'get_meeting_tasks'],
        ],
        'workspace_files' => [
            'description' => 'Workspaces and files: list/read/write/search/move/copy/delete files and directories.',
            'tools' => ['list_workspaces', 'create_workspace', 'delete_workspace', 'list_workspace_files', 'read_workspace_file', 'search_workspace_files', 'create_workspace_directory', 'write_workspace_file', 'delete_workspace_file', 'copy_workspace_file', 'move_workspace_file'],
        ],
        'github' => [
            'description' => 'GitHub: repositories, branches, files, pull requests.',
            'tools' => ['github_get_branch', 'github_get_repository', 'github_get_tree', 'github_get_file_contents', 'github_create_branch', 'github_create_or_update_file', 'github_create_pull_request', 'github_get_pull_request_comments'],
        ],
        'code_changes' => [
            'description' => 'Git commits and the engineering changelog: what changed/shipped/was added or fixed in a code repository over a period, recent commits, commit details, the last saved changelog report, and whether a tracker task/issue exists for a given commit.',
            'tools' => ['github_list_commits', 'github_get_commit', 'github_get_repository', 'get_last_commit_report', 'get_issue_candidates', 'search_issues_by_text', 'get_issue_detail'],
        ],
        'memory_decisions' => [
            'description' => 'Team decisions and organizational memory: save/search decisions and facts.',
            'tools' => ['save_team_decision', 'search_team_decisions', 'query_db'],
        ],
        'metrics' => [
            'description' => 'Performance metrics for a user or team.',
            'tools' => ['get_user_metrics', 'get_team_metrics'],
        ],
        'focus' => [
            'description' => "Managing the user's current focus and focused issues.",
            'tools' => ['set_user_focus', 'get_user_focus', 'clear_user_focus', 'get_focused_issues'],
        ],
        'notifications' => [
            'description' => 'User notifications.',
            'tools' => ['get_user_notifications'],
        ],
        'documents' => [
            'description' => 'Fetching and reading external documents/links.',
            'tools' => ['fetch_document'],
        ],
        'methodology' => [
            'description' => 'Creating or saving meeting/followup methodologies and artifacts.',
            'tools' => ['create_methodology', 'save_methodology'],
        ],
    ];

    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {}

    /**
     * Resolve the set of tool names to expose for this message, or null if the
     * router is unavailable/failed (caller should keep the full toolset).
     *
     * @return array<int, string>|null
     */
    public function selectToolNames(string $userMessage): ?array
    {
        if (! config('agent.tool_router.enabled', false)) {
            return null;
        }

        $categories = $this->chooseCategories($userMessage);
        if ($categories === null) {
            return null;
        }

        $names = self::ALWAYS_ON;
        foreach ($categories as $category) {
            if (isset(self::CATEGORIES[$category])) {
                $names = array_merge($names, self::CATEGORIES[$category]['tools']);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, string>|null
     */
    private function chooseCategories(string $userMessage): ?array
    {
        $catalogue = [];
        foreach (self::CATEGORIES as $key => $meta) {
            $catalogue[] = "- {$key}: {$meta['description']}";
        }

        $prompt = "You route a user's message to the tool categories an assistant may need.\n"
            ."Available categories:\n".implode("\n", $catalogue)."\n\n"
            .'Return ONLY a JSON object: {"categories": ["key1", "key2"]}. '
            .'Pick every category that could plausibly be needed; omit clearly irrelevant ones. '
            ."If unsure, include the category.\n\n"
            ."User message:\n".$userMessage;

        try {
            $raw = $this->llm->chat(
                [['role' => 'user', 'content' => $prompt]],
                (string) config('agent.tool_router.model', 'openai/gpt-4.1-mini'),
                300,
                true,
                [],
                (int) config('agent.tool_router.timeout_seconds', 12),
            );

            $decoded = json_decode((string) $raw, true);
            $categories = is_array($decoded) ? ($decoded['categories'] ?? null) : null;

            if (! is_array($categories)) {
                Log::warning('Tool router returned no categories, keeping full toolset', ['raw' => $raw]);

                return null;
            }

            $valid = array_values(array_intersect(
                array_map(static fn ($c) => is_string($c) ? trim($c) : '', $categories),
                array_keys(self::CATEGORIES),
            ));

            Log::info('Tool router selected categories', ['categories' => $valid]);

            return $valid;
        } catch (\Throwable $e) {
            Log::warning('Tool router failed, keeping full toolset', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
