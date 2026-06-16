<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Channel\ChannelBus;
use App\Services\Issue\IncompleteContentNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IncompleteContentNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'IC Org', 'slug' => 'ic-org']);
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create(['name' => 'Default Methodology', 'text' => 'Default methodology text', 'scheme' => '{}', 'is_default' => true]);
        $this->team = Team::create([
            'name' => 'IC Team',
            'slug' => 'ic-team',
            'organization_id' => $this->org->id,
            'methodology_id' => $methodology->id,
        ]);
        $this->author = User::factory()->create();
    }

    private function makeIssue(?int $userId = null, ?int $teamId = null): Issue
    {
        return Issue::create([
            'user_id'         => $userId ?? $this->author->id,
            'organization_id' => $this->org->id,
            'team_id'         => $teamId ?? $this->team->id,
            'name'            => 'Incomplete issue',
            'description'     => '## Пункты\n1. a',
            'type'            => 'development',
            'status'          => 'open',
        ]);
    }

    private function makeNotifier(?ChannelBus $busMock = null): IncompleteContentNotifier
    {
        return new IncompleteContentNotifier(
            $busMock ?? $this->app->make(ChannelBus::class),
        );
    }

    #[Test]
    public function it_posts_in_app_chat_when_chat_exists(): void
    {
        $issue = $this->makeIssue();
        $chat = Chat::create([
            'user_id'         => $this->author->id,
            'team_id'         => $this->team->id,
            'organization_id' => $this->org->id,
            'title'           => 'Existing chat',
        ]);

        $busMock = Mockery::mock(ChannelBus::class);
        $busMock->shouldReceive('createChatAssistantMessage')
            ->once()
            ->withArgs(function (Chat $passedChat, string $content) use ($chat, $issue): bool {
                return $passedChat->id === $chat->id
                    && str_contains($content, '#'.$issue->id);
            });

        $notifier = $this->makeNotifier($busMock);
        $notifier->notify($issue, ['context', 'dod']);
    }

    #[Test]
    public function it_silently_skips_in_app_when_no_chat_exists(): void
    {
        $issue = $this->makeIssue();

        $busMock = Mockery::mock(ChannelBus::class);
        $busMock->shouldNotReceive('createChatAssistantMessage');

        $notifier = $this->makeNotifier($busMock);
        $notifier->notify($issue, ['context']);
        // No exception — silent skip is the expected behavior.
        $this->assertTrue(true);
    }

    #[Test]
    public function it_does_nothing_when_missing_sections_array_empty(): void
    {
        $issue = $this->makeIssue();

        $busMock = Mockery::mock(ChannelBus::class);
        $busMock->shouldNotReceive('createChatAssistantMessage');

        $notifier = $this->makeNotifier($busMock);
        $notifier->notify($issue, []);
    }

    #[Test]
    public function it_skips_demo_author_without_meeting_fallback(): void
    {
        $demoUser = User::factory()->create();
        $demoUser->forceFill(['is_demo' => true])->save();

        $issue = $this->makeIssue($demoUser->id);

        $busMock = Mockery::mock(ChannelBus::class);
        $busMock->shouldNotReceive('createChatAssistantMessage');

        $notifier = $this->makeNotifier($busMock);
        $notifier->notify($issue, ['context']);
    }

    #[Test]
    public function it_picks_most_recently_updated_chat_in_scope(): void
    {
        $issue = $this->makeIssue();

        $older = Chat::create([
            'user_id'         => $this->author->id,
            'team_id'         => $this->team->id,
            'organization_id' => $this->org->id,
            'title'           => 'Older',
        ]);
        $older->updated_at = now()->subDay();
        $older->save();

        $newer = Chat::create([
            'user_id'         => $this->author->id,
            'team_id'         => $this->team->id,
            'organization_id' => $this->org->id,
            'title'           => 'Newer',
        ]);

        $busMock = Mockery::mock(ChannelBus::class);
        $busMock->shouldReceive('createChatAssistantMessage')
            ->once()
            ->withArgs(fn (Chat $passedChat) => $passedChat->id === $newer->id);

        $notifier = $this->makeNotifier($busMock);
        $notifier->notify($issue, ['context']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
