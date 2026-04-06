<?php

namespace App\Jobs\Demo;

use App\Models\CalendarEvent;
use App\Models\AgentActivityLog;
use App\Models\DemoGeneration;
use App\Models\Participant;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\Demo\DemoTranscriptGeneratorService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDemoTranscriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries    = 3;
    public int $timeout  = 180;

    /**
     * @param  array  $currentEvent   ['event_id' => int, 'meeting_type' => string]
     * @param  array  $remainingEvents  Remaining events to process after this one
     * @param  int    $totalCount       Total number of events in the chain (constant across jobs)
     */
    public function __construct(
        private readonly int   $generationId,
        private readonly array $currentEvent,
        private readonly array $remainingEvents,
        private readonly int   $totalCount,
    ) {
    }

    public function handle(DemoTranscriptGeneratorService $transcriptGenerator): void
    {
        $generation = DemoGeneration::findOrFail($this->generationId);

        $currentNum    = $this->totalCount - count($this->remainingEvents);
        $progressBase  = 20;
        $progressRange = 55; // 20% → 75% during transcript generation
        $progressPct   = $progressBase + (int) ($progressRange * (($currentNum - 1) / max(1, $this->totalCount)));

        $generation->updateProgress(
            "Генерация транскрипции встречи ({$currentNum}/{$this->totalCount})...",
            $progressPct
        );

        try {
            $eventId     = $this->currentEvent['event_id'];
            $meetingType = $this->currentEvent['meeting_type'];

            $event = CalendarEvent::findOrFail($eventId);

            // Find which team this event belongs to
            $teamData = $this->findTeamData($generation, $eventId);

            if (!$teamData) {
                Log::warning('GenerateDemoTranscriptJob: no team data for event', ['event_id' => $eventId]);
                $this->dispatchNext($generation);
                return;
            }

            // Build personas for this team
            $personas = $this->getPersonas($teamData);

            if (empty($personas)) {
                Log::warning('GenerateDemoTranscriptJob: no personas for event', ['event_id' => $eventId]);
                $this->dispatchNext($generation);
                return;
            }

            // Generate transcript via LLM
            $transcriptLines = $transcriptGenerator->generate($personas, $meetingType);

            if (empty($transcriptLines)) {
                Log::warning('GenerateDemoTranscriptJob: empty transcript generated', [
                    'event_id'    => $eventId,
                    'meetingType' => $meetingType,
                ]);
                $this->dispatchNext($generation);
                return;
            }

            // Build name → profile map for participants
            $nameToProfile = $this->buildNameToProfileMap($teamData);

            // Create participants and transcript entries
            $this->persistTranscript($event, $transcriptLines, $nameToProfile);

            Log::info('GenerateDemoTranscriptJob: transcript created', [
                'event_id' => $eventId,
                'lines'    => count($transcriptLines),
            ]);

            if ($generation->user) {
                AgentActivityLog::recordActivity(
                    user: $generation->user,
                    toolName: 'demo_transcript_generated',
                    toolResult: [
                        'count' => count($transcriptLines),
                        'demo_generation_id' => $generation->id,
                        'event_id' => $eventId,
                    ],
                );
            }

            // Dispatch pipeline processing for this event
            ProcessDemoEventJob::dispatch($this->generationId, $eventId);

            // Continue with next event or finalize
            $this->dispatchNext($generation);
        } catch (\Throwable $e) {
            Log::error('GenerateDemoTranscriptJob: failed', [
                'event_id' => $this->currentEvent['event_id'] ?? null,
                'error'    => $e->getMessage(),
            ]);
            $generation->markFailed('Ошибка генерации транскрипции: ' . $e->getMessage());
        }
    }

    private function findTeamData(DemoGeneration $generation, int $eventId): ?array
    {
        $data = $generation->data ?? [];
        foreach ($data['teams'] ?? [] as $teamData) {
            foreach ($teamData['events'] ?? [] as $eventData) {
                if ($eventData['event_id'] === $eventId) {
                    return $teamData;
                }
            }
        }
        return null;
    }

    private function getPersonas(array $teamData): array
    {
        $personas = $teamData['personas'] ?? [];
        if (empty($personas)) {
            // Fallback: use user names only
            return array_map(function ($userId) {
                $user = User::find($userId);
                return [
                    'name'          => $user?->name ?? 'Участник',
                    'role'          => 'Специалист',
                    'speaking_style' => 'Говорит по делу',
                ];
            }, $teamData['user_ids'] ?? []);
        }
        return $personas;
    }

    private function buildNameToProfileMap(array $teamData): array
    {
        $map = [];
        foreach ($teamData['personas'] ?? [] as $persona) {
            $userId    = $persona['user_id'] ?? null;
            $profileId = null;

            // Find profile_id for this user from profile_ids list
            $userIndex = array_search($userId, $teamData['user_ids'] ?? []);
            if ($userIndex !== false && isset($teamData['profile_ids'][$userIndex])) {
                $profileId = $teamData['profile_ids'][$userIndex];
            }

            if ($profileId) {
                $map[$persona['name']] = $profileId;
            }
        }
        return $map;
    }

    private function persistTranscript(CalendarEvent $event, array $lines, array $nameToProfile): void
    {
        foreach ($lines as $line) {
            $speakerName = $line['speaker_name'] ?? '';
            $text        = $line['text'] ?? '';
            $offset      = (int) ($line['offset_seconds'] ?? 0);

            if (empty($speakerName) || empty($text)) {
                continue;
            }

            $profileId = $this->resolveProfileId($speakerName, $nameToProfile);

            // When profile_id is null, include name in search keys to avoid
            // collapsing multiple unmatched speakers into one participant record.
            if ($profileId !== null) {
                $participant = Participant::firstOrCreate(
                    ['calendar_event_id' => $event->id, 'profile_id' => $profileId],
                    ['name' => $speakerName]
                );
            } else {
                $participant = Participant::firstOrCreate([
                    'calendar_event_id' => $event->id,
                    'profile_id'        => null,
                    'name'              => $speakerName,
                ]);
            }

            $startAbsolute = Carbon::parse($event->starts_at)->addSeconds($offset);

            TranscriptEntry::firstOrCreate(
                [
                    'calendar_event_id' => $event->id,
                    'participant_id'    => $participant->id,
                    'start_relative'    => $offset,
                ],
                [
                    'text'           => $text,
                    'end_relative'   => $offset + 15,
                    'start_absolute' => $startAbsolute,
                    'end_absolute'   => $startAbsolute->copy()->addSeconds(15),
                ]
            );
        }
    }

    /**
     * Try to match speaker name to a profile ID using fuzzy matching.
     */
    private function resolveProfileId(string $speakerName, array $nameToProfile): ?int
    {
        // Exact match
        if (isset($nameToProfile[$speakerName])) {
            return $nameToProfile[$speakerName];
        }

        // Partial match: check if any key starts with or contains the speaker name
        $speakerLower = mb_strtolower($speakerName);
        foreach ($nameToProfile as $name => $profileId) {
            if (str_contains(mb_strtolower($name), $speakerLower)
                || str_contains($speakerLower, mb_strtolower(explode(' ', $name)[0] ?? ''))) {
                return $profileId;
            }
        }

        return null;
    }

    private function dispatchNext(DemoGeneration $generation): void
    {
        if (!empty($this->remainingEvents)) {
            $nextEvent       = $this->remainingEvents[0];
            $remaining       = array_slice($this->remainingEvents, 1);
            GenerateDemoTranscriptJob::dispatch($this->generationId, $nextEvent, $remaining, $this->totalCount);
        } else {
            // All transcripts done — wait for ProcessDemoEventJobs, then relationships
            ProcessDemoRelationshipsJob::dispatch($this->generationId)->delay(now()->addSeconds(30));
        }
    }
}
