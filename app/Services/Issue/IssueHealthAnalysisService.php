<?php

namespace App\Services\Issue;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\IssueHealthReport;
use App\Models\Setting;
use App\Models\Team;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class IssueHealthAnalysisService
{
    public function __construct(
        private readonly IssueHealthDetector $detector,
        private readonly OpenRouterClient $llm,
    ) {}

    public function generate(Team $team): ?IssueHealthReport
    {
        $findings = $this->detector->detect($team);

        if (! $this->detector->hasProblems($findings)) {
            return null;
        }

        $aiSummary = $this->generateSummary($team, $findings);

        return IssueHealthReport::updateOrCreate(
            [
                'team_id'         => $team->id,
                'organization_id' => $team->organization_id,
                'period_start'    => today()->toDateString(),
            ],
            [
                'findings'     => $findings,
                'ai_summary'   => $aiSummary,
                'generated_at' => now(),
                'expires_at'   => now()->addDays(30),
            ]
        );
    }

    private function generateSummary(Team $team, array $findings): ?string
    {
        $prompt = app(LlmPromptService::class)->renderView(
            slug: 'issue.health.analysis.user',
            organizationId: $team->organization_id,
            fallbackView: 'llm-prompts.issue.health-analysis-user',
            variables: [
                'team_name'     => $team->name,
                'analysis_date' => today()->format('d.m.Y'),
                'findings'      => json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ],
            name: 'Issue health analysis prompt',
        );

        try {
            $result = $this->llm->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.digest', config('ai.providers.openrouter.models.digest')),
                maxTokens: 512,
            );

            return is_string($result) ? trim($result) : null;
        } catch (\Throwable $e) {
            Log::warning('IssueHealthAnalysisService: LLM summary failed', [
                'team_id' => $team->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
