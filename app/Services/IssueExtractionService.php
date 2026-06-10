<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\TranscriptBuilderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class IssueExtractionService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly IssueMergeService $issueMerge,
    ) {}

    /**
     * Extract actionable issues from a meeting transcript and persist them.
     *
     * @return Collection<int, Issue>
     */
    public function extract(CalendarEvent $event, Team $team, User $user): Collection
    {
        try {
            $items = $this->computeItems($event, $team);
        } catch (\Throwable $e) {
            // LLM/JSON failure: swallow + return empty, exactly as before (Recall path must not retry here).
            Log::error('Issue extraction failed', [
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        // null sentinel = blank transcript: no work, no logging (byte-for-byte with the old early return).
        if ($items === null) {
            return collect();
        }

        // NB: an empty-but-valid LLM result ($items === []) intentionally still flows through persist()
        // + Log::info + AgentActivityLog (count=0), preserving the pre-split behavior.
        $issues = $this->issueMerge->persist($items, $event, $team, $user);

        Log::info('Issues extracted from transcript', [
            'calendar_event_id' => $event->id,
            'team_id' => $team->id,
            'count' => $issues->count(),
        ]);

        AgentActivityLog::recordActivity(
            user: $user,
            toolName: 'issues_extracted',
            toolResult: [
                'count' => $issues->count(),
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
            ],
            toolArgs: [
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
            ],
        );

        return $issues;
    }

    /**
     * COMPUTE half — Pass-1 LLM extraction, NO writes.
     *
     * Returns the filtered raw items array, or NULL when the transcript is blank (the "no work"
     * sentinel that lets extract() skip persist/logging exactly as before). Unlike extract(), this
     * method does NOT swallow LLM failures — it lets them propagate so the moderation gate's queued
     * compute job can retry (tries=3) and fail the plan section instead of silently auto-approving
     * an empty plan.
     *
     * @return array<int, array>|null
     */
    public function computeItems(CalendarEvent $event, Team $team): ?array
    {
        $transcript = $this->transcriptBuilder->build($event);

        if (blank($transcript)) {
            return null;
        }

        $orgContext = $team->organization?->context;

        $endOfWeek = now()->endOfWeek(\Carbon\Carbon::FRIDAY)->toDateString();

        $messages = [
            new MessageDTO('system', $this->buildSystemPrompt($orgContext)),
            new MessageDTO('user', "Дата встречи: {$event->starts_at->toDateString()}\nТекущая дата: ".now()->toDateString()."\nКонец текущей недели (дедлайн по умолчанию): {$endOfWeek}\n\nТранскрипт встречи:\n".$transcript),
        ];

        $json = $this->llm->chat(
            messages: $messages,
            model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
            maxTokens: 4096,
            forceJsonResponse: true,
        );

        if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
            $json = $matches[0];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        $items = $decoded['issues'] ?? [];

        return array_values(array_filter($items, fn ($item) => trim($item['name'] ?? '') !== ''));
    }

    private function buildSystemPrompt(?string $orgContext = null): string
    {
        $contextSection = $orgContext
            ? "\n## Organization context\n\nUse this to better understand the domain, team roles, and terminology when extracting tasks:\n\n{$orgContext}\n"
            : '';

        return app(LlmPromptService::class)->renderView(
            slug: 'issue.extraction.system',
            organizationId: null,
            fallbackView: 'llm-prompts.issue.extraction-system',
            variables: ['context_section' => $contextSection],
            name: 'Issue extraction system prompt',
        );
    }
}
