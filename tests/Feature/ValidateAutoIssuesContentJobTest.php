<?php

namespace Tests\Feature;

use App\Jobs\ValidateAutoIssuesContentJob;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Issue\IncompleteContentNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidateAutoIssuesContentJobTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Vc Org', 'slug' => 'vc-org']);
        $this->author = User::factory()->create();
    }

    private function makeIssue(string $description, string $type = 'development'): Issue
    {
        return Issue::create([
            'user_id'         => $this->author->id,
            'organization_id' => $this->org->id,
            'name'            => 'Issue',
            'description'     => $description,
            'type'            => $type,
            'status'          => 'open',
        ]);
    }

    private function runJob(array $ids, ?IncompleteContentNotifier $notifierMock = null): void
    {
        $job = new ValidateAutoIssuesContentJob($ids);
        $job->handle(
            $this->app->make(\App\Services\Issue\IssueContentValidator::class),
            $notifierMock ?? $this->app->make(IncompleteContentNotifier::class),
        );
    }

    #[Test]
    public function it_notifies_for_issue_missing_sections(): void
    {
        $issue = $this->makeIssue("## Пункты\n1. one");

        $mock = Mockery::mock(IncompleteContentNotifier::class);
        $mock->shouldReceive('notify')
            ->once()
            ->withArgs(function (Issue $passedIssue, array $missing) use ($issue): bool {
                return $passedIssue->id === $issue->id
                    && in_array('context', $missing, true)
                    && in_array('dod', $missing, true);
            });

        $this->runJob([$issue->id], $mock);
    }

    #[Test]
    public function it_does_not_notify_for_fully_filled_issue(): void
    {
        $issue = $this->makeIssue(
            "## Контекст\nctx\n\n## Пункты\n1. a\n\n## Definition of done\nyes"
        );

        $mock = Mockery::mock(IncompleteContentNotifier::class);
        $mock->shouldNotReceive('notify');

        $this->runJob([$issue->id], $mock);
    }

    #[Test]
    public function it_handles_empty_id_array(): void
    {
        $mock = Mockery::mock(IncompleteContentNotifier::class);
        $mock->shouldNotReceive('notify');

        $this->runJob([], $mock);
    }

    #[Test]
    public function it_validates_epic_without_requiring_dod(): void
    {
        $epic = $this->makeIssue(
            "## Контекст\nctx\n\n## Пункты\n1. a",
            'epic'
        );

        $mock = Mockery::mock(IncompleteContentNotifier::class);
        $mock->shouldNotReceive('notify');

        $this->runJob([$epic->id], $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
