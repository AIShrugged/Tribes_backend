<?php

namespace Tests\Unit;

use App\Services\Agent\ConversationCompactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConversationCompactionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_snapshot_for_conversation_key(): void
    {
        config()->set('agent.compaction.keep_recent_messages', 2);

        $service = $this->app->make(ConversationCompactionService::class);
        $history = new Collection([
            $this->message(1, 'user', 'Question 1'),
            $this->message(2, 'assistant', 'Answer 1'),
            $this->message(3, 'user', 'Question 2'),
            $this->message(4, 'assistant', 'Answer 2'),
        ]);

        $compacted = $service->compact($history, 'chat:test');

        $this->assertNotNull($compacted->summary);
        $this->assertDatabaseHas('conversation_compaction_snapshots', [
            'conversation_key' => 'chat:test',
            'keep_recent_messages' => 2,
            'message_count' => 2,
            'last_message_id' => 2,
        ]);
    }

    #[Test]
    public function it_incrementally_extends_snapshot_when_history_grows(): void
    {
        config()->set('agent.compaction.keep_recent_messages', 2);

        $service = $this->app->make(ConversationCompactionService::class);
        $initialHistory = new Collection([
            $this->message(1, 'user', 'Question 1'),
            $this->message(2, 'assistant', 'Answer 1'),
            $this->message(3, 'user', 'Question 2'),
            $this->message(4, 'assistant', 'Answer 2'),
        ]);

        $service->compact($initialHistory, 'chat:test');

        $expandedHistory = new Collection([
            $this->message(1, 'user', 'Question 1'),
            $this->message(2, 'assistant', 'Answer 1'),
            $this->message(3, 'user', 'Question 2'),
            $this->message(4, 'assistant', 'Answer 2'),
            $this->message(5, 'user', 'Question 3'),
        ]);

        $compacted = $service->compact($expandedHistory, 'chat:test');

        $this->assertNotNull($compacted->summary);
        $this->assertStringContainsString('Question 2', $compacted->summary);
        $this->assertDatabaseHas('conversation_compaction_snapshots', [
            'conversation_key' => 'chat:test',
            'message_count' => 3,
            'last_message_id' => 3,
        ]);
    }

    private function message(int $id, string $role, string $content): object
    {
        return (object) [
            'id' => $id,
            'role' => $role,
            'content' => $content,
        ];
    }
}
