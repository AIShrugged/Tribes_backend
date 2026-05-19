<?php

namespace Tests\Feature;

use App\Enums\IssueAgentFlowStatus;
use App\Enums\IssueAgentFlowStepKind;
use App\Enums\IssueAgentFlowStepStatus;
use App\Exceptions\AppException;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use App\Models\IssueAgentFlowPendingReply;
use App\Models\IssueAgentFlowStep;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\Tools\AnswerIssueValidationTool;
use App\Services\Agent\Tools\GetPendingIssueValidationsTool;
use App\Services\Issue\ValidationReplyHandler;
use App\Services\IssueAgentFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueAgentFlowValidationReplyTest extends TestCase
{
    use RefreshDatabase;

    private const TG_USER_ID = 800101;
    private const TG_QUESTION_MESSAGE_ID = 999;

    #[Test]
    public function reply_on_pending_question_calls_service_and_returns_acceptance(): void
    {
        $ctx = $this->makePendingValidation();

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldReceive('answer')
            ->once()
            ->with(Mockery::on(fn ($issue) => $issue instanceof Issue && $issue->id === $ctx['issue']->id), 'context, dod, scope');

        $handler = new ValidationReplyHandler($serviceMock);
        $outcome = $handler->handleTelegramReply(
            self::TG_USER_ID,
            self::TG_QUESTION_MESSAGE_ID,
            $ctx['user']->id,
            'context, dod, scope',
        );

        $this->assertNotNull($outcome);
        $this->assertTrue($outcome->accepted);
        $this->assertSame($ctx['issue']->id, $outcome->issueId);
        $this->assertStringContainsString('✅', $outcome->message);
        $this->assertStringContainsString('#' . $ctx['issue']->id, $outcome->message);

        $ctx['pending']->refresh();
        $this->assertNotNull($ctx['pending']->consumed_at, 'pending must be marked consumed after reply');
    }

    #[Test]
    public function reply_from_other_user_is_ignored(): void
    {
        $ctx = $this->makePendingValidation();
        $foreignUser = User::factory()->create();

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldNotReceive('answer');

        $handler = new ValidationReplyHandler($serviceMock);
        $outcome = $handler->handleTelegramReply(
            self::TG_USER_ID,
            self::TG_QUESTION_MESSAGE_ID,
            $foreignUser->id,
            'whatever',
        );

        $this->assertNull($outcome, 'foreign user reply must not match');

        $ctx['pending']->refresh();
        $this->assertNull($ctx['pending']->consumed_at);
    }

    #[Test]
    public function reply_on_consumed_pending_is_ignored(): void
    {
        $ctx = $this->makePendingValidation();
        $ctx['pending']->update(['consumed_at' => now()->subMinute()]);

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldNotReceive('answer');

        $handler = new ValidationReplyHandler($serviceMock);
        $outcome = $handler->handleTelegramReply(
            self::TG_USER_ID,
            self::TG_QUESTION_MESSAGE_ID,
            $ctx['user']->id,
            'answer',
        );

        $this->assertNull($outcome);
    }

    #[Test]
    public function reply_on_expired_pending_is_ignored(): void
    {
        $ctx = $this->makePendingValidation();
        $ctx['pending']->update(['expires_at' => now()->subDay()]);

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldNotReceive('answer');

        $handler = new ValidationReplyHandler($serviceMock);
        $outcome = $handler->handleTelegramReply(
            self::TG_USER_ID,
            self::TG_QUESTION_MESSAGE_ID,
            $ctx['user']->id,
            'answer',
        );

        $this->assertNull($outcome);

        $ctx['pending']->refresh();
        $this->assertNull($ctx['pending']->consumed_at);
    }

    #[Test]
    public function reply_on_wrong_message_id_is_ignored(): void
    {
        $ctx = $this->makePendingValidation();

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldNotReceive('answer');

        $handler = new ValidationReplyHandler($serviceMock);
        $outcome = $handler->handleTelegramReply(
            self::TG_USER_ID,
            self::TG_QUESTION_MESSAGE_ID + 1,
            $ctx['user']->id,
            'answer',
        );

        $this->assertNull($outcome);

        $ctx['pending']->refresh();
        $this->assertNull($ctx['pending']->consumed_at);
    }

    #[Test]
    public function reply_when_flow_already_left_waiting_consumes_with_warning(): void
    {
        $ctx = $this->makePendingValidation();

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldReceive('answer')
            ->once()
            ->andThrow(new AppException(
                'No flow in WAITING_FOR_USER state found for this issue.',
                'ISSUE_AGENT_FLOW_NOT_WAITING',
                422,
            ));

        $handler = new ValidationReplyHandler($serviceMock);
        $outcome = $handler->handleTelegramReply(
            self::TG_USER_ID,
            self::TG_QUESTION_MESSAGE_ID,
            $ctx['user']->id,
            'late answer',
        );

        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->accepted);
        $this->assertStringContainsString('⚠️', $outcome->message);
        $this->assertStringContainsString('#' . $ctx['issue']->id, $outcome->message);

        $ctx['pending']->refresh();
        $this->assertNotNull($ctx['pending']->consumed_at);
    }

    #[Test]
    public function answer_tool_consumes_pending_and_delegates_to_service(): void
    {
        $ctx = $this->makePendingValidation();

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldReceive('answer')->once();

        $tool = new AnswerIssueValidationTool($ctx['user'], $serviceMock);
        $result = $tool->execute(['issue_id' => $ctx['issue']->id, 'answers' => 'context, dod, scope']);

        $this->assertTrue($result['success']);
        $this->assertSame($ctx['issue']->id, $result['issue_id']);

        $ctx['pending']->refresh();
        $this->assertNotNull($ctx['pending']->consumed_at);
    }

    #[Test]
    public function answer_tool_rejects_when_no_pending_validation(): void
    {
        $user = $this->makeUser();
        $issue = $this->makeIssue($user);

        $serviceMock = Mockery::mock(IssueAgentFlowService::class);
        $serviceMock->shouldNotReceive('answer');

        $tool = new AnswerIssueValidationTool($user, $serviceMock);
        $result = $tool->execute(['issue_id' => $issue->id, 'answers' => 'hi']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No active pending validation', $result['error']);
    }

    #[Test]
    public function get_pending_tool_lists_active_validations_for_current_user(): void
    {
        $ctx = $this->makePendingValidation();

        $tool = new GetPendingIssueValidationsTool($ctx['user']);
        $result = $tool->execute([]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['pending_count']);
        $this->assertSame($ctx['issue']->id, $result['pending'][0]['issue_id']);
        $this->assertSame(['What is the context?', 'What is the DoD?'], $result['pending'][0]['questions']);
    }

    #[Test]
    public function get_pending_tool_excludes_consumed_and_expired_records(): void
    {
        $ctx = $this->makePendingValidation();
        $ctx['pending']->update(['consumed_at' => now()]);

        $tool = new GetPendingIssueValidationsTool($ctx['user']);
        $result = $tool->execute([]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['pending_count']);
    }

    // ------- helpers -------

    /**
     * @return array{user: User, issue: Issue, flow: IssueAgentFlow, pending: IssueAgentFlowPendingReply}
     */
    private function makePendingValidation(): array
    {
        $user = $this->makeUser();
        $issue = $this->makeIssue($user);

        $flow = IssueAgentFlow::create([
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
            'status' => IssueAgentFlowStatus::WAITING_FOR_USER->value,
            'metadata' => ['issue_type' => $issue->type, 'issue_name' => $issue->name],
        ]);

        IssueAgentFlowStep::create([
            'issue_agent_flow_id' => $flow->id,
            'position' => 0,
            'kind' => IssueAgentFlowStepKind::VALIDATION->value,
            'title' => 'Validate',
            'prompt' => 'prompt',
            'definition' => ['kind' => 'validation'],
            'status' => IssueAgentFlowStepStatus::WAITING_FOR_USER->value,
        ]);

        $pending = IssueAgentFlowPendingReply::create([
            'issue_agent_flow_id' => $flow->id,
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'telegram_chat_id' => self::TG_USER_ID,
            'telegram_message_id' => self::TG_QUESTION_MESSAGE_ID,
            'questions' => ['What is the context?', 'What is the DoD?'],
            'expires_at' => now()->addDays(14),
        ]);

        return compact('user', 'issue', 'flow', 'pending');
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        TelegramUser::query()->updateOrCreate(
            ['telegram_user_id' => self::TG_USER_ID],
            ['telegram_username' => 'tester_' . $user->id, 'user_id' => $user->id],
        );

        return $user;
    }

    private function makeIssue(User $user): Issue
    {
        [$organization, $team] = $this->createTenantContextFor($user);

        return Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Sample backend issue',
            'description' => 'Initial description',
            'type' => Issue::TYPE_BACKEND,
            'status' => 'open',
        ]);
    }

    private function createTenantContextFor(User $user): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Validation Reply Org ' . uniqid(),
            'slug' => 'validation-reply-' . uniqid(),
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Backend',
            'slug' => 'backend-' . uniqid(),
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
