<?php

namespace Tests\Feature;

use App\Enums\InsightContextType;
use App\Models\Channel;
use App\Models\InsightShortTerm;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserFocusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::firstOrCreate(['name' => 'web']);

        $this->user = User::factory()->create();

        $this->profile = Profile::create([
            'channel_id'         => $channel->id,
            'channel_identifier' => (string) $this->user->id,
            'user_id'            => $this->user->id,
        ]);
    }

    #[Test]
    public function get_focus_returns_null_when_no_focus_is_set(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/focus')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    }

    #[Test]
    public function put_focus_creates_a_new_focus_record(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', ['focus_text' => 'Ship v2.0'])
            ->assertOk()
            ->assertJsonPath('data.focus_text', 'Ship v2.0')
            ->assertJsonPath('data.deadline', null);

        $this->assertDatabaseHas('insight_short_term', [
            'profile_id'   => $this->profile->id,
            'context_type' => InsightContextType::USER_FOCUS->value,
        ]);
    }

    #[Test]
    public function put_focus_with_deadline_stores_deadline(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', ['focus_text' => 'Ship v2.0', 'deadline' => '2026-04-25'])
            ->assertOk()
            ->assertJsonPath('data.focus_text', 'Ship v2.0')
            ->assertJsonPath('data.deadline', '2026-04-25');
    }

    #[Test]
    public function put_focus_replaces_existing_focus(): void
    {
        InsightShortTerm::setFocus($this->profile->id, 'Old focus', null, now()->addDays(14));

        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', ['focus_text' => 'New focus'])
            ->assertOk()
            ->assertJsonPath('data.focus_text', 'New focus');

        $this->assertSame(
            1,
            InsightShortTerm::forFocus($this->profile->id)->count()
        );
    }

    #[Test]
    public function get_focus_returns_active_focus(): void
    {
        InsightShortTerm::setFocus($this->profile->id, 'Current focus', '2026-04-25', now()->addDays(14));

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/focus')
            ->assertOk()
            ->assertJsonPath('data.focus_text', 'Current focus')
            ->assertJsonPath('data.deadline', '2026-04-25');
    }

    #[Test]
    public function delete_focus_removes_the_record(): void
    {
        InsightShortTerm::setFocus($this->profile->id, 'Focus to delete', null, now()->addDays(14));

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me/focus')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);

        $this->assertDatabaseMissing('insight_short_term', [
            'profile_id'   => $this->profile->id,
            'context_type' => InsightContextType::USER_FOCUS->value,
        ]);
    }

    #[Test]
    public function put_focus_strips_html_tags_from_focus_text(): void
    {
        // strip_tags removes tags but keeps inner text — "<b>Ship</b> v2.0" → "Ship v2.0"
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', ['focus_text' => '<b>Ship</b> v2.0'])
            ->assertOk()
            ->assertJsonPath('data.focus_text', 'Ship v2.0');
    }

    #[Test]
    public function put_focus_requires_focus_text(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', [])
            ->assertUnprocessable();
    }

    #[Test]
    public function put_focus_rejects_invalid_deadline_format(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', ['focus_text' => 'Ship v2.0', 'deadline' => 'not-a-date'])
            ->assertUnprocessable();
    }

    #[Test]
    public function get_focus_returns_null_for_expired_focus(): void
    {
        InsightShortTerm::setFocus($this->profile->id, 'Expired focus', null, now()->subDay());

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/focus')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/me/focus')->assertUnauthorized();
        $this->putJson('/api/v1/me/focus', ['focus_text' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/v1/me/focus')->assertUnauthorized();
    }

    #[Test]
    public function user_without_profile_gets_graceful_response_on_get(): void
    {
        $userWithoutProfile = User::factory()->create();

        $this->actingAs($userWithoutProfile)
            ->getJson('/api/v1/me/focus')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    }

    #[Test]
    public function user_without_profile_gets_graceful_response_on_delete(): void
    {
        $userWithoutProfile = User::factory()->create();

        $this->actingAs($userWithoutProfile)
            ->deleteJson('/api/v1/me/focus')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    }

    #[Test]
    public function put_focus_rejects_focus_text_exceeding_500_chars(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/focus', ['focus_text' => str_repeat('a', 501)])
            ->assertUnprocessable();
    }
}