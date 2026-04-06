<?php

namespace Tests\Unit;

use App\Models\AgentActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentActivityLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_formats_count_based_activity_descriptions(): void
    {
        $this->assertSame(
            'Заэкстрактил 5 задач',
            AgentActivityLog::descriptionFor('meeting_tasks_extracted', ['count' => 5]),
        );

        $this->assertSame(
            'Обработал 3 задач из Telegram',
            AgentActivityLog::descriptionFor('telegram_tasks_processed', ['count' => 3]),
        );
    }

    #[Test]
    public function it_records_generic_activity_rows(): void
    {
        $user = User::factory()->create();

        $log = AgentActivityLog::recordActivity(
            user: $user,
            toolName: 'agent_run_started',
            toolResult: ['count' => 1, 'message_length' => 42],
            toolArgs: ['channel' => 'web'],
        );

        $this->assertDatabaseHas('agent_activity_logs', [
            'id' => $log->id,
            'user_id' => $user->id,
            'tool_name' => 'agent_run_started',
            'description' => 'Начал обработку запроса',
            'success' => true,
        ]);
    }
}
