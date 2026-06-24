<?php

namespace Tests\Feature;

use App\Enums\OutputMode;
use App\Jobs\ProcessTelegramWorkerJob;
use App\Models\Organization;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;
use App\Services\Agent\TelegramMessageCoalescer;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Channel\ChannelBus;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\TelegramTypingIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessTelegramWorkerJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function private_telegram_chat_does_not_scope_agent_to_users_latest_organization(): void
    {
        $user = User::factory()->create();
        $first = Organization::query()->create(['name' => 'First Org', 'slug' => 'first-org']);
        $second = Organization::query()->create(['name' => 'Second Org', 'slug' => 'second-org']);
        $latest = Organization::query()->create(['name' => 'Latest Org', 'slug' => 'latest-org']);

        $user->organizations()->attach($first->id, ['role' => 'manager', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $user->organizations()->attach($second->id, ['role' => 'manager', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $user->organizations()->attach($latest->id, ['role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);

        $telegramUser = TelegramUser::query()->create([
            'telegram_user_id' => 123456,
            'telegram_username' => 'manager',
            'user_id' => $user->id,
        ]);

        $channelBus = app(ChannelBus::class);
        $message = $channelBus->appendTelegramMessage(
            777001,
            $telegramUser,
            null,
            'user',
            'покажи все организации',
        );

        $batch = app(TelegramMessageCoalescer::class)->claimPendingBatch(777001);
        $this->assertNotNull($batch);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('run')
            ->once()
            ->withArgs(function (User $actualUser, $history, string $content, AgentRunOptions $options) use ($user, $batch): bool {
                $this->assertTrue($actualUser->is($user));
                // Telegram content is wrapped as untrusted data and the run is tainted
                // (lethal-trifecta mitigation) — the raw batch text is still present inside.
                $this->assertStringContainsString($batch->content, $content);
                $this->assertStringContainsString('<untrusted_data', $content);
                $this->assertTrue($options->untrustedInput);
                $this->assertSame(OutputMode::MD, $options->outputMode);
                $this->assertNull($options->organizationId);
                $this->assertFalse($options->enableSqlTool);

                return true;
            })
            ->andReturn('Вот все организации.');

        $runtimeService = Mockery::mock(ChannelRuntimeService::class);
        $runtimeService->shouldReceive('deliverToConversation')
            ->once()
            ->andReturnUsing(fn () => $message);

        $typingIndicator = Mockery::mock(TelegramTypingIndicator::class);
        $typingIndicator->shouldReceive('sessionId')->andReturn('telegram:777001:root');
        $typingIndicator->shouldReceive('start')->once();
        $typingIndicator->shouldReceive('touch')->zeroOrMoreTimes();
        $typingIndicator->shouldReceive('stop')->once();

        $job = new ProcessTelegramWorkerJob(
            chatId: $batch->chatId,
            authorIdentityId: $batch->authorIdentity->id,
            userId: $user->id,
            batchUuid: $batch->batchUuid,
            content: $batch->content,
            messageThreadId: null,
        );

        $job->handle(
            $agentService,
            app(ToolRegistry::class),
            app(TelegramMessageCoalescer::class),
            $channelBus,
            $runtimeService,
            $typingIndicator,
        );
    }
}
