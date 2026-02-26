<?php

namespace App\Jobs\Demo;

use App\Models\DemoGeneration;
use App\Models\User;
use App\Services\Demo\DemoPersonaGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDemoPersonasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $generationId)
    {
    }

    public function handle(DemoPersonaGeneratorService $personaGenerator): void
    {
        $generation = DemoGeneration::findOrFail($this->generationId);
        $generation->updateProgress('Генерация персонажей команды...', 15);

        try {
            $data   = $generation->data ?? ['teams' => []];
            $params = $generation->params;
            $employeesPerTeam = $params['employees_per_team'] ?? 7;

            foreach ($data['teams'] as $index => &$teamData) {
                $teamNumber = $index + 1;
                $teamContext = count($data['teams']) > 1
                    ? "Команда {$teamNumber} из " . count($data['teams'])
                    : '';

                // Generate personas for this team
                $personas = $personaGenerator->generate($employeesPerTeam, $teamContext);

                // Update demo user names with generated personas
                foreach ($teamData['user_ids'] as $i => $userId) {
                    $persona = $personas[$i] ?? null;
                    if (!$persona) {
                        continue;
                    }

                    $user = User::find($userId);
                    if ($user) {
                        $user->update(['name' => $persona['name']]);
                    }

                    // Store persona data in team data
                    $teamData['personas'][$i] = array_merge($persona, ['user_id' => $userId]);
                }

                Log::info('GenerateDemoPersonasJob: personas generated for team', [
                    'team_index' => $index,
                    'count'      => count($personas),
                ]);
            }
            unset($teamData);

            $generation->update(['data' => $data]);

            // Collect all event IDs in order across all teams
            $allEvents = [];
            foreach ($data['teams'] as $teamData) {
                foreach ($teamData['events'] as $eventData) {
                    $allEvents[] = $eventData;
                }
            }

            if (empty($allEvents)) {
                $generation->markReady();
                return;
            }

            // Dispatch first transcript job
            $totalCount      = count($allEvents);
            $firstEvent      = array_shift($allEvents);
            $remainingEvents = $allEvents;

            GenerateDemoTranscriptJob::dispatch($this->generationId, $firstEvent, $remainingEvents, $totalCount);
        } catch (\Throwable $e) {
            Log::error('GenerateDemoPersonasJob: failed', ['error' => $e->getMessage()]);
            $generation->markFailed('Ошибка генерации персонажей: ' . $e->getMessage());
        }
    }
}
