<?php

namespace App\Jobs\Demo;

use App\Enums\UserRole;
use App\Jobs\Demo\GenerateDemoPersonasJob;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\DemoGeneration;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Demo\DemoTranscriptGeneratorService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeedDemoStructureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $generationId)
    {
    }

    public function handle(): void
    {
        $generation = DemoGeneration::findOrFail($this->generationId);
        $generation->updateProgress('Setting up structure (organization, teams)...', 5);

        $params = $generation->params ?? [];
        $teamsCount        = $params['teams_count'] ?? 1;
        $employeesPerTeam  = $params['employees_per_team'] ?? 7;
        $meetingsPerTeam   = $params['meetings_per_team'] ?? 3;
        $owner             = $generation->user;

        try {
            // 1. Create demo organization
            $org = Organization::create([
                'name' => 'Demo — ' . ($owner->name ?? 'Команда'),
                'slug' => 'demo-' . $owner->id . '-' . Str::random(6),
            ]);

            $generation->update(['organization_id' => $org->id]);

            // Add owner as manager
            $org->users()->attach($owner->id, ['role' => UserRole::MANAGER->value]);

            $defaultMethodology = Methodology::getDefault();
            $gcChannelId        = Channel::idFor('google_calendar');

            // 2. Create owner's source (calendar events will belong to it)
            $ownerSource = Source::firstOrCreate(
                ['user_id' => $owner->id, 'type' => 'google_calendar', 'identity' => $owner->email],
                [
                    'external_id' => 'demo_owner_' . $owner->id,
                    'auth_type'   => 'none',
                    'is_connected' => true,
                ]
            );

            $teamsData = [];

            for ($t = 0; $t < $teamsCount; $t++) {
                // 3. Create team
                $team = $org->teams()->create([
                    'name'           => 'Team ' . ($t + 1),
                    'slug'           => 'demo-' . $owner->id . '-team-' . ($t + 1),
                    'methodology_id' => $defaultMethodology->id,
                ]);

                $userIds    = [];
                $profileIds = [];

                // 4. Create demo employees
                for ($e = 0; $e < $employeesPerTeam; $e++) {
                    $demoEmail = 'demo_user_' . Str::random(8) . '@demo-' . $owner->id . '.internal';

                    // Use forceFill to bypass User::$fillable (is_demo not in fillable)
                    $demoUser = (new User)->forceFill([
                        'name'              => 'Demo User ' . ($e + 1), // placeholder, replaced by personas job
                        'email'             => $demoEmail,
                        'password'          => Hash::make(Str::random(32)),
                        'is_demo'           => true,
                        'email_verified_at' => now(),
                    ]);
                    $demoUser->save();

                    // Add to org as employee
                    $org->users()->attach($demoUser->id, ['role' => UserRole::EMPLOYEE->value]);

                    // Add to team
                    $team->users()->attach($demoUser->id);

                    // Create profile on google_calendar channel (required for InsightExtractionService)
                    $profile = Profile::create([
                        'user_id'            => $demoUser->id,
                        'channel_id'         => $gcChannelId,
                        'channel_identifier' => $demoEmail,
                    ]);

                    // Create source for this demo user
                    Source::create([
                        'user_id'     => $demoUser->id,
                        'type'        => 'google_calendar',
                        'identity'    => $demoEmail,
                        'external_id' => 'demo_emp_' . $demoUser->id,
                        'auth_type'   => 'none',
                        'is_connected' => true,
                    ]);

                    $userIds[]    = $demoUser->id;
                    $profileIds[] = $profile->id;
                }

                // 5. Create meeting placeholders for this team
                $meetingTypes = DemoTranscriptGeneratorService::getMeetingTypes($meetingsPerTeam);
                $eventIds     = [];

                foreach ($meetingTypes as $i => $meetingType) {
                    $startsAt = Carbon::now()->subDays($meetingsPerTeam - $i)->setHour(10 + $i * 2)->setMinute(0)->setSecond(0);
                    $duration = $this->getMeetingDuration($meetingType);

                    $event = CalendarEvent::create([
                        'source_id'   => $ownerSource->id,
                        'external_id' => 'demo_event_' . $generation->id . '_' . $t . '_' . $i,
                        'platform'    => 'google_meet',
                        'title'       => DemoTranscriptGeneratorService::getMeetingTitle($meetingType),
                        'description' => '',
                        'starts_at'   => $startsAt,
                        'ends_at'     => $startsAt->copy()->addMinutes($duration),
                        'url'         => 'https://meet.google.com/demo-' . Str::random(10),
                        'required_bot' => false,
                    ]);

                    $eventIds[] = ['event_id' => $event->id, 'meeting_type' => $meetingType];
                }

                $teamsData[] = [
                    'team_id'    => $team->id,
                    'user_ids'   => $userIds,
                    'profile_ids' => $profileIds,
                    'events'     => $eventIds,
                    'personas'   => [], // filled by GenerateDemoPersonasJob
                ];
            }

            $generation->update(['data' => ['teams' => $teamsData]]);

            Log::info('SeedDemoStructureJob: structure created', [
                'generation_id' => $this->generationId,
                'org_id'        => $org->id,
                'teams_count'   => $teamsCount,
            ]);

            GenerateDemoPersonasJob::dispatch($this->generationId);
        } catch (\Throwable $e) {
            Log::error('SeedDemoStructureJob: failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $generation->markFailed('Ошибка создания структуры: ' . $e->getMessage());
        }
    }

    private function getMeetingDuration(string $type): int
    {
        return match ($type) {
            'standup'       => 20,
            'planning'      => 90,
            'retrospective' => 60,
            'one_on_one'    => 30,
            'architecture'  => 60,
            'incident_review' => 45,
            default         => 30,
        };
    }
}
