<?php

namespace Tests\Unit\Models;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): Conversation
    {
        return Conversation::create(['channel_type' => ChannelType::Web]);
    }

    // --- role helpers ---

    /** @test */
    public function it_identifies_user_role(): void
    {
        $msg = Message::create([
            'conversation_id' => $this->makeConversation()->id,
            'role'            => 'user',
            'content'         => 'Hello',
        ]);

        $this->assertTrue($msg->isFromUser());
        $this->assertFalse($msg->isFromAssistant());
    }

    /** @test */
    public function it_identifies_assistant_role(): void
    {
        $msg = Message::create([
            'conversation_id' => $this->makeConversation()->id,
            'role'            => 'assistant',
            'content'         => 'Hi there',
        ]);

        $this->assertFalse($msg->isFromUser());
        $this->assertTrue($msg->isFromAssistant());
    }

    // --- followup_data via metadata ---

    /** @test */
    public function it_detects_followup_data_in_metadata(): void
    {
        $msg = Message::create([
            'conversation_id' => $this->makeConversation()->id,
            'role'            => 'assistant',
            'content'         => 'Answer',
            'metadata'        => ['followup_data' => ['key' => 'value']],
        ]);

        $this->assertTrue($msg->hasFollowup());
    }

    /** @test */
    public function it_returns_false_for_missing_followup_data(): void
    {
        $msg = Message::create([
            'conversation_id' => $this->makeConversation()->id,
            'role'            => 'assistant',
            'content'         => 'Answer',
        ]);

        $this->assertFalse($msg->hasFollowup());
    }

    /** @test */
    public function it_returns_followup_data_from_metadata(): void
    {
        $followup = ['summary' => 'test', 'actions' => []];

        $msg = Message::create([
            'conversation_id' => $this->makeConversation()->id,
            'role'            => 'assistant',
            'content'         => 'Answer',
            'metadata'        => ['followup_data' => $followup],
        ]);

        $this->assertEquals($followup, $msg->getFollowupData());
    }

    /** @test */
    public function it_returns_null_followup_data_when_absent(): void
    {
        $msg = Message::create([
            'conversation_id' => $this->makeConversation()->id,
            'role'            => 'user',
            'content'         => 'Question',
        ]);

        $this->assertNull($msg->getFollowupData());
    }
}
