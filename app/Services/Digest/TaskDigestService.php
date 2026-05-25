<?php

namespace App\Services\Digest;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\TaskDigest;
use App\Models\User;
use App\Services\LlmPromptService;
use App\Services\Metrics\PerformanceMetricsService;
use App\Services\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates AI-analyzed task digests (daily or weekly) per (user, organization).
 *
 * Storage: unified `task_digests` table with period_type discriminator.
 * Content: Three Ps framework (progress / problems / priorities) + metrics snapshot.
 * Anti-fatigue: returns null when neither problems nor wins exist.
 * Anti-injection: user-supplied strings wrapped in delimited blocks with explicit
 * instruction to treat content as data.
 */
class TaskDigestService
{
    private const CONTENT_SCHEMA_VERSION = 1;

    public function __construct(
        private readonly PerformanceMetricsService $metricsService,
        private readonly ProblemDetector $problemDetector,
        private readonly OpenRouterClient $llm,
    ) {}

    /**
     * Returns cached digest if fresh, otherwise null. No LLM call.
     */
    public function getCached(User $user, Organization $org, string $periodType, Carbon $periodStart): ?array
    {
        $digest = TaskDigest::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->where('period_type', $periodType)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if (! $digest || $digest->isExpired()) {
            return null;
        }

        return $digest->content;
    }

    /**
     * Generates digest content via LLM, persists, dispatches notification, returns content.
     *
     * Returns null if (a) user has no activity worth reporting (anti-fatigue),
     * (b) LLM call fails. Idempotent — repeated calls produce the same row.
     */
    public function generate(User $user, Organization $org, string $periodType, Carbon $periodStart): ?array
    {
        if (! $user->isOrganizationMember($org)) {
            Log::info('TaskDigestService: user is not org member, skipping', [
                'user_id' => $user->id,
                'organization_id' => $org->id,
            ]);
            return null;
        }

        $period = $this->resolvePeriod($periodType, $periodStart);
        $facts = $this->problemDetector->detect($user, $org->id, $period);

        // Anti-fatigue
        if (! $facts['has_content']) {
            Log::info('TaskDigestService: no meaningful content, skipping', [
                'user_id' => $user->id,
                'organization_id' => $org->id,
                'period_type' => $periodType,
            ]);
            return null;
        }

        $isManager = $user->isOrganizationManager($org);
        $userMetrics = $this->metricsService->forUser($user, $period);
        $managerExtras = $isManager ? $this->collectManagerExtras($org, $period) : null;

        $content = $this->callLLM(
            user: $user,
            org: $org,
            periodType: $periodType,
            period: $period,
            facts: $facts,
            userMetrics: $userMetrics,
            managerExtras: $managerExtras,
        );

        if ($content === null) {
            return null;
        }

        TaskDigest::updateOrCreate(
            [
                'user_id' => $user->id,
                'organization_id' => $org->id,
                'period_type' => $periodType,
                'period_start' => $periodStart->toDateString(),
            ],
            [
                'content' => $content,
                'expires_at' => Carbon::now()->addDays(30),
            ],
        );

        // Note: dashboard notification is created by the caller (SendMorningBriefCommand for daily,
        // SendWeeklyTaskDigestsCommand for weekly) — NOT here — to avoid double-notify if generate
        // runs and morning brief also runs the same day.

        return $content;
    }

    private function callLLM(
        User $user,
        Organization $org,
        string $periodType,
        array $period,
        array $facts,
        array $userMetrics,
        ?array $managerExtras,
    ): ?array {
        try {
            $prompt = $this->buildPrompt($user, $org, $periodType, $period, $facts, $userMetrics, $managerExtras);

            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.digest', config('ai.providers.openrouter.models.digest')) ?: 'google/gemini-3.1-pro-preview',
                maxTokens: 2048,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            // Look for JSON-wrapped output (some models prefix text before JSON).
            if (! is_array($data) && preg_match('/\{[\s\S]*\}/s', (string) $json, $matches)) {
                $data = json_decode($matches[0], true);
            }

            if (! is_array($data)) {
                Log::warning('TaskDigestService: unexpected LLM response', ['user_id' => $user->id]);
                return null;
            }

            return [
                'schema_version' => self::CONTENT_SCHEMA_VERSION,
                'kind' => $periodType,
                'period' => [
                    'from' => $period['from']->toDateString(),
                    'to' => $period['to']->toDateString(),
                ],
                'progress' => array_values((array) ($data['progress'] ?? [])),
                'problems' => array_values((array) ($data['problems'] ?? [])),
                'priorities' => array_values((array) ($data['priorities'] ?? [])),
                'goals_commentary' => array_values((array) ($data['goals_commentary'] ?? [])),
                'metrics_snapshot' => $userMetrics,
                'manager_extras' => $managerExtras,
            ];
        } catch (\Throwable $e) {
            Log::warning('TaskDigestService: LLM call failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildPrompt(
        User $user,
        Organization $org,
        string $periodType,
        array $period,
        array $facts,
        array $userMetrics,
        ?array $managerExtras,
    ): string {
        $kindLabel = $periodType === TaskDigest::PERIOD_WEEKLY ? 'еженедельный' : 'ежедневный';
        $userName = $this->sanitize($user->name);
        $orgName = $this->sanitize($org->name);

        // Sanitize all user-supplied strings before injection
        $factsJson = json_encode($this->sanitizeFacts($facts), JSON_UNESCAPED_UNICODE);
        $metricsJson = json_encode($userMetrics, JSON_UNESCAPED_UNICODE);
        $managerJson = $managerExtras !== null
            ? json_encode($this->sanitizeFacts($managerExtras), JSON_UNESCAPED_UNICODE)
            : 'null';

        return app(LlmPromptService::class)->renderView(
            slug: 'digest.task.user',
            organizationId: $org->id,
            fallbackView: 'llm-prompts.digest.task-user',
            variables: [
                'kind_label' => $kindLabel,
                'user_name' => $userName,
                'organization_name' => $orgName,
                'facts_json' => $factsJson,
                'metrics_json' => $metricsJson,
                'manager_json' => $managerJson,
            ],
            name: 'Task digest prompt',
        );
    }

    /**
     * Anti-prompt-injection sanitizer: strip control chars + role markers, cap length.
     */
    private function sanitize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_ireplace(['system:', 'assistant:', 'user:', '<<<', '>>>'], '', $value);
        return mb_substr(trim($value), 0, 200);
    }

    /**
     * Recursively sanitize all string values in a facts array.
     */
    private function sanitizeFacts(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->sanitize($value);
            }
            if (is_array($value)) {
                return $this->sanitizeFacts($value);
            }
            return $value;
        }, $data);
    }

    /**
     * @return array{teams_breakdown: array, organization_goals: array}|null
     */
    private function collectManagerExtras(Organization $org, array $period): ?array
    {
        $orgMetrics = $this->metricsService->forOrg($org, $period);

        $breakdown = [];
        foreach ($orgMetrics['by_team'] as $teamId => $teamMetrics) {
            $breakdown[] = [
                'team_id' => $teamId,
                'team_name' => $org->teams->firstWhere('id', $teamId)?->name,
                'done' => $teamMetrics['aggregated']['done'] ?? 0,
                'in_progress' => $teamMetrics['aggregated']['in_progress'] ?? 0,
                'overdue' => $teamMetrics['aggregated']['overdue'] ?? 0,
            ];
        }

        return [
            'teams_breakdown' => $breakdown,
            'organization_goals' => $this->collectOrganizationGoals($org),
        ];
    }

    /**
     * Goals = epics. An "epic" is an `Issue` with type='epic' tied to the organization.
     * Sub-issues link to their epic via `epic_id`. Progress is derived from children state.
     *
     * Only active (non-done, non-cancelled) epics are returned — closed epics are achievements,
     * not goals to discuss in a forward-looking weekly digest.
     */
    private function collectOrganizationGoals(Organization $org): array
    {
        $epics = Issue::query()
            ->withoutTrashed()
            ->where('organization_id', $org->id)
            ->where('type', 'epic')
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->with('assignee:id,name')
            ->orderBy('id')
            ->get();

        if ($epics->isEmpty()) {
            return [];
        }

        // Batch-load child stats per epic to avoid N+1
        $epicIds = $epics->pluck('id')->all();
        $childStats = Issue::query()
            ->withoutTrashed()
            ->whereIn('epic_id', $epicIds)
            ->select('epic_id', 'status', 'due_date')
            ->get()
            ->groupBy('epic_id');

        $today = Carbon::now()->toDateString();
        $openStatuses = ['open', 'in_progress', 'paused', 'review', 'reopen'];

        $goals = [];
        foreach ($epics as $epic) {
            $children = $childStats->get($epic->id, collect());
            $total = $children->count();
            $done = $children->where('status', 'done')->count();
            $open = $children->whereIn('status', $openStatuses)->count();
            $overdue = $children->whereIn('status', $openStatuses)
                ->filter(fn ($c) => $c->due_date !== null && $c->due_date->toDateString() < $today)
                ->count();
            $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;

            $goals[] = [
                'epic_id' => $epic->id,
                'name' => $epic->name,
                'status' => $epic->status,
                'due_date' => $epic->due_date?->toDateString(),
                'assignee_name' => $epic->assignee?->name,
                'children' => [
                    'total' => $total,
                    'done' => $done,
                    'open' => $open,
                    'overdue' => $overdue,
                    'progress_pct' => $pct,
                ],
            ];
        }

        return $goals;
    }

    private function resolvePeriod(string $periodType, Carbon $periodStart): array
    {
        if ($periodType === TaskDigest::PERIOD_WEEKLY) {
            return [
                'from' => $periodStart->copy()->startOfDay(),
                'to' => $periodStart->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay(),
            ];
        }

        // daily — 24h window ending now for "yesterday + today" feeling.
        return [
            'from' => $periodStart->copy()->subDay()->startOfDay(),
            'to' => $periodStart->copy()->endOfDay(),
        ];
    }
}
