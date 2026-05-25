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
        $transcript = $this->transcriptBuilder->build($event);

        if (blank($transcript)) {
            return collect();
        }

        $orgContext = $team->organization?->context;

        $messages = [
            new MessageDTO('system', $this->buildSystemPrompt($orgContext)),
            new MessageDTO('user', "Дата встречи: {$event->starts_at->toDateString()}\nТекущая дата: ".now()->toDateString()."\n\nТранскрипт встречи:\n".$transcript),
        ];

        try {
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
        } catch (\Throwable $e) {
            Log::error('Issue extraction failed', [
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        $items = array_values(array_filter($items, fn ($item) => trim($item['name'] ?? '') !== ''));

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
