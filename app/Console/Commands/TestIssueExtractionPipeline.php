<?php

namespace App\Console\Commands;

use App\Events\IssuesExtracted;
use App\Models\CalendarEvent;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\Team;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\IssueExtractionService;
use Illuminate\Console\Command;

class TestIssueExtractionPipeline extends Command
{
    protected $signature = 'pipeline:test-extraction
        {--user-id= : Existing user ID (creates test user if omitted)}
        {--team-id= : Existing team ID (creates test team if omitted)}
        {--skip-agent-tasks : Extract issues but skip agent task dispatch}';

    protected $description = 'Создаёт тестовый транскрипт, экстрактит issues, диспатчит agent tasks';

    public function handle(IssueExtractionService $extractionService): int
    {
        $this->info('=== Issue Extraction Pipeline Test ===');

        // 1. Resolve or create user + team
        $user = $this->resolveUser();
        $team = $this->resolveTeam($user);

        $this->info("User: {$user->name} (#{$user->id})");
        $this->info("Team: {$team->name} (#{$team->id})");

        // 2. Create calendar event + transcript
        $event = $this->createTestMeeting($user);
        $this->seedTranscript($event);
        $this->info("CalendarEvent #{$event->id} created with transcript entries");

        // 3. Extract issues
        $this->newLine();
        $this->info('--- Extracting issues from transcript ---');
        $issues = $extractionService->extract($event, $team, $user);

        if ($issues->isEmpty()) {
            $this->warn('No issues extracted. Check LLM connection / logs.');
            return self::FAILURE;
        }

        $this->info("Extracted {$issues->count()} issue(s):");
        foreach ($issues as $issue) {
            $this->line("  [{$issue->type}] #{$issue->id} — {$issue->name}");
        }

        // 4. Dispatch agent tasks
        if ($this->option('skip-agent-tasks')) {
            $this->info('Skipping agent task dispatch (--skip-agent-tasks)');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('--- Dispatching agent tasks via IssuesExtracted event ---');
        IssuesExtracted::dispatch($issues, $team, $user);

        foreach ($issues->map(fn ($i) => $i->fresh()) as $issue) {
            if ($issue->agent_task_id) {
                $this->line("  Issue #{$issue->id} → AgentTask #{$issue->agent_task_id}");
            } else {
                $this->warn("  Issue #{$issue->id} — no agent task created");
            }
        }

        $this->newLine();
        $this->info('Pipeline complete.');

        return self::SUCCESS;
    }

    private function resolveUser(): User
    {
        if ($id = $this->option('user-id')) {
            return User::findOrFail($id);
        }

        return User::firstOrCreate(
            ['email' => 'pipeline-test@test.local'],
            ['name' => 'Pipeline Test User', 'password' => bcrypt('password')],
        );
    }

    private function resolveTeam(User $user): Team
    {
        if ($id = $this->option('team-id')) {
            $team = Team::findOrFail($id);
            if (! $team->users()->where('users.id', $user->id)->exists()) {
                $team->users()->attach($user);
            }
            return $team;
        }

        $org = Organization::firstOrCreate(
            ['slug' => 'pipeline-test-org'],
            ['name' => 'Pipeline Test Org'],
        );

        if (! $org->users()->where('users.id', $user->id)->exists()) {
            $org->users()->attach($user, ['role' => 'manager']);
        }

        $methodology = Methodology::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Default'],
            ['text' => 'Default methodology', 'scheme' => json_encode(['type' => 'object'])],
        );

        $team = Team::firstOrCreate(
            ['slug' => 'pipeline-test-team', 'organization_id' => $org->id],
            ['name' => 'Pipeline Test Team', 'methodology_id' => $methodology->id],
        );

        if (! $team->users()->where('users.id', $user->id)->exists()) {
            $team->users()->attach($user);
        }

        return $team;
    }

    private function createTestMeeting(User $user): CalendarEvent
    {
        $identity = $user->email ?? 'test@test.local';

        $source = Source::firstOrCreate(
            ['user_id' => $user->id, 'identity' => $identity, 'type' => 'google_calendar'],
            ['external_id' => 'pipeline-test-source'],
        );

        return CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'pipeline-test-' . now()->timestamp,
            'platform' => 'google_meet',
            'title' => 'Sprint Review — Pipeline Test',
            'url' => 'https://meet.google.com/pipeline-test',
            'description' => 'Test meeting for issue extraction pipeline',
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
            'required_bot' => false,
        ]);
    }

    private function seedTranscript(CalendarEvent $event): void
    {
        $pm = Participant::create(['calendar_event_id' => $event->id, 'name' => 'Алексей (PM)']);
        $dev = Participant::create(['calendar_event_id' => $event->id, 'name' => 'Мария (Dev)']);
        $qa = Participant::create(['calendar_event_id' => $event->id, 'name' => 'Иван (QA)']);

        $lines = [
            [$pm, 'Привет всем! Давайте пройдёмся по итогам спринта и обсудим что нужно сделать.', 0, 6],
            [$dev, 'У нас проблема - нужно срочно оценить покрытие кода и сделать тесты на сущность Source', 6, 14],
            [$qa, 'Да, и ещё нужно протестировать интеграцию с Recall.ai, там могут быть баги', 14, 22],
            [$pm, 'Хорошо, давайте поставим эти задачи на следующую неделю. Мария, ты займёшься тестами для Source, а Иван - интеграцией с Recall.ai', 22, 35],
            [$dev, 'Окей, я начну с оценки покрытия и напишу тесты для Source', 35, 42],
            [$qa, 'А я проверю текущую интеграцию с Recall.ai и напишу тесты для неё', 42, 50],
        ];

        $base = now()->subHour();
        foreach ($lines as [$participant, $text, $start, $end]) {
            TranscriptEntry::create([
                'calendar_event_id' => $event->id,
                'participant_id' => $participant->id,
                'text' => $text,
                'start_relative' => (float) $start,
                'end_relative' => (float) $end,
                'start_absolute' => $base->copy()->addSeconds($start),
                'end_absolute' => $base->copy()->addSeconds($end),
            ]);
        }
    }
}
