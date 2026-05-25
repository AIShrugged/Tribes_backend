<?php

namespace App\Services;

use Illuminate\Contracts\View\Factory as ViewFactory;

class LlmPromptDefaultRegistry
{
    /**
     * @return array<int, array{slug: string, name: string, view: string}>
     */
    public function all(): array
    {
        return [
            ['slug' => 'agent.system', 'name' => 'Agent system prompt', 'view' => 'llm-prompts.shared.prompt-body'],
            ['slug' => 'agenda.general.user', 'name' => 'General agenda prompt', 'view' => 'llm-prompts.shared.prompt-body'],
            ['slug' => 'agenda.meeting_series_state.user', 'name' => 'Meeting series state prompt', 'view' => 'llm-prompts.shared.prompt-body'],
            ['slug' => 'agenda.personal.user', 'name' => 'Personal agenda prompt', 'view' => 'llm-prompts.shared.prompt-body'],
            ['slug' => 'agenda.upcoming.user', 'name' => 'Upcoming agenda prompt', 'view' => 'llm-prompts.shared.prompt-body'],
            ['slug' => 'chat.wanda.system', 'name' => 'Wanda report chat system prompt', 'view' => 'llm-prompts.shared.prompt-body'],
            ['slug' => 'critical_path.agent_analysis.task', 'name' => 'Critical path agent analysis task prompt', 'view' => 'llm-prompts.critical-path.agent-analysis-task'],
            ['slug' => 'critical_path.analysis.system', 'name' => 'Critical path analysis system prompt', 'view' => 'llm-prompts.critical-path.analysis-system'],
            ['slug' => 'decisions.extract.authors.user', 'name' => 'Decision author extraction prompt', 'view' => 'llm-prompts.decisions.extract-authors-user'],
            ['slug' => 'demo.personas.user', 'name' => 'Demo persona generation prompt', 'view' => 'llm-prompts.demo.personas-user'],
            ['slug' => 'demo.transcript.user', 'name' => 'Demo transcript generation prompt', 'view' => 'llm-prompts.demo.transcript-user'],
            ['slug' => 'digest.meetings_advice.user', 'name' => 'Meetings advice prompt', 'view' => 'llm-prompts.digest.meetings-advice-user'],
            ['slug' => 'digest.task.user', 'name' => 'Task digest prompt', 'view' => 'llm-prompts.digest.task-user'],
            ['slug' => 'followup.shared_stayfitt_v1.system', 'name' => 'Shared StayFitt v1 system prompt', 'view' => 'llm-prompts.followup.shared-stayfitt-v1-system'],
            ['slug' => 'followup.shared_stayfitt_v1.user', 'name' => 'Shared StayFitt v1 user prompt', 'view' => 'llm-prompts.followup.shared-stayfitt-v1-user'],
            ['slug' => 'issue.conflict_detector.system', 'name' => 'Issue conflict detector system prompt', 'view' => 'llm-prompts.issue.conflict-detector-system'],
            ['slug' => 'issue.epic_extraction.system', 'name' => 'Epic extraction system prompt', 'view' => 'llm-prompts.issue.epic-extraction-system'],
            ['slug' => 'issue.extraction.system', 'name' => 'Issue extraction system prompt', 'view' => 'llm-prompts.issue.extraction-system'],
            ['slug' => 'issue.merge.system', 'name' => 'Issue merge system prompt', 'view' => 'llm-prompts.issue.merge-system'],
            ['slug' => 'meeting.participant_profile_matching.user', 'name' => 'Participant profile matching prompt', 'view' => 'llm-prompts.meeting.participant-profile-matching-user'],
            ['slug' => 'meeting.repeated_discussions.system', 'name' => 'Repeated discussion detection system prompt', 'view' => 'llm-prompts.meeting.repeated-discussions-system'],
            ['slug' => 'meeting.review.user', 'name' => 'Meeting review prompt', 'view' => 'llm-prompts.meeting.review-user'],
            ['slug' => 'meeting.summary.user', 'name' => 'Meeting summary prompt', 'view' => 'llm-prompts.meeting.summary-user'],
            ['slug' => 'meeting.verify_artifacts.coverage.user', 'name' => 'Meeting artifact coverage prompt', 'view' => 'llm-prompts.meeting.verify-artifacts-coverage-user'],
            ['slug' => 'methodology.schema.system', 'name' => 'Methodology schema system prompt', 'view' => 'llm-prompts.methodology.schema-system'],
            ['slug' => 'methodology.schema.user.1', 'name' => 'Methodology schema user prompt 1', 'view' => 'llm-prompts.methodology.schema-user-v1'],
            ['slug' => 'methodology.schema.user.2', 'name' => 'Methodology schema user prompt 2', 'view' => 'llm-prompts.methodology.schema-user-v2'],
            ['slug' => 'onboarding.combined.system', 'name' => 'Combined onboarding system prompt', 'view' => 'llm-prompts.onboarding.combined-system'],
            ['slug' => 'onboarding.combined_browsing.system', 'name' => 'Combined onboarding browsing system prompt', 'view' => 'llm-prompts.onboarding.combined-browsing-system'],
            ['slug' => 'telegram.tasks.user', 'name' => 'Telegram task extraction prompt', 'view' => 'llm-prompts.telegram.tasks-user'],
            ['slug' => 'today.daily_nudge.user', 'name' => 'Daily nudge prompt', 'view' => 'llm-prompts.today.daily-nudge-user'],
        ];
    }

    public function render(string $view): string
    {
        return app(ViewFactory::class)->make($view)->render();
    }
}
