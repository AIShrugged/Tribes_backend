<?php

namespace Tests\Unit;

use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\FollowupArtifactStateService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FollowupArtifactStateServiceTest extends TestCase
{
    #[Test]
    public function legacy_followup_payload_is_wrapped_into_artifact_state(): void
    {
        $service = new FollowupArtifactStateService();

        $followup = $this->makeFollowup([
            'summary' => 'Legacy summary',
            'action_items' => ['Task 1', 'Task 2'],
        ]);

        $state = $service->toState($followup);

        $this->assertArrayHasKey('artifacts', $state);
        $this->assertArrayHasKey('layout', $state);

        $artifactId = 'followup_'.$followup->id;
        $this->assertArrayHasKey($artifactId, $state['artifacts']);
        $artifact = $state['artifacts'][$artifactId];

        $this->assertSame('methodology_criteria', $artifact['type']);
        $this->assertSame('ready', $artifact['status']);
        $this->assertArrayHasKey('blocks', $artifact['data']);
        $this->assertSame($artifactId, $state['layout']['items'][0]['id']);
    }

    private function makeFollowup(array $payload): Followup
    {
        $user = new User(['id' => 1, 'name' => 'Alice']);
        $team = new Team(['id' => 2, 'name' => 'Core']);
        $methodology = new Methodology(['id' => 3, 'name' => 'Sales', 'scheme_version' => '1']);
        $event = new CalendarEvent(['id' => 4, 'title' => 'Weekly Sync']);

        $followup = new Followup([
            'id' => 5,
            'status' => 'done',
            'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $followup->setRelation('user', $user);
        $followup->setRelation('team', $team);
        $followup->setRelation('methodology', $methodology);
        $followup->setRelation('calendarEvent', $event);

        return $followup;
    }
}
