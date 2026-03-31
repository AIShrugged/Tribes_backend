<?php

namespace Tests\Feature;

use App\Domain\DTO\OauthDTO;
use App\Enums\SourceAuthType;
use App\Enums\SourceType;
use App\Models\OAuthState;
use App\Models\Source;
use App\Models\SourceOauth;
use App\Models\User;
use App\Services\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_restores_a_soft_deleted_source_when_the_same_calendar_email_is_reconnected(): void
    {
        config(['app.frontend_url' => 'http://frontend.test']);

        $user = User::factory()->create();
        $state = OAuthState::create([
            'user_id' => $user->id,
            'state' => 'state-soft-deleted',
        ]);

        $source = Source::create([
            'user_id' => $user->id,
            'external_id' => 'old-recall-calendar-id',
            'identity' => $user->email,
            'auth_type' => SourceAuthType::OAUTH2->value,
            'type' => SourceType::GOOGLE_CALENDAR->value,
            'is_connected' => false,
        ]);
        $source->delete();

        $this->mockGoogleOAuthCallback(
            new OauthDTO('new-access-token', 'new-refresh-token', 3600, $user->email)
        );

        Http::fake([
            'https://us-west-2.recall.ai/api/v2/calendars/' => Http::response([
                'id' => 'new-recall-calendar-id',
            ], 200),
        ]);

        $response = $this->get('/api/v1/google/oauth/callback?state='.$state->state.'&code=test-code');

        $response->assertRedirect('http://frontend.test/dashboard/calendar?attached=1');

        $this->assertSame(1, Source::withTrashed()->where([
            'user_id' => $user->id,
            'identity' => $user->email,
            'type' => SourceType::GOOGLE_CALENDAR->value,
        ])->count());

        $source->refresh();

        $this->assertFalse($source->trashed());
        $this->assertSame('new-recall-calendar-id', $source->external_id);
        $this->assertSame(SourceAuthType::OAUTH2->value, $source->auth_type);
        $this->assertTrue((bool) $source->is_connected);

        $oauth = SourceOauth::where('source_id', $source->id)->firstOrFail();
        $this->assertSame('new-access-token', $oauth->access_token);
        $this->assertSame('new-refresh-token', $oauth->refresh_token);
        $this->assertSame($user->email, $oauth->email);
        $this->assertTrue(Carbon::parse($oauth->expires_at)->greaterThan(now()));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://us-west-2.recall.ai/api/v2/calendars/';
        });
    }

    #[Test]
    public function it_creates_a_new_source_for_a_new_google_calendar_email(): void
    {
        config(['app.frontend_url' => 'http://frontend.test']);

        $user = User::factory()->create();
        $state = OAuthState::create([
            'user_id' => $user->id,
            'state' => 'state-new-source',
        ]);

        $this->mockGoogleOAuthCallback(
            new OauthDTO('fresh-access-token', 'fresh-refresh-token', 1800, $user->email)
        );

        Http::fake([
            'https://us-west-2.recall.ai/api/v2/calendars/' => Http::response([
                'id' => 'fresh-recall-calendar-id',
            ], 200),
        ]);

        $response = $this->get('/api/v1/google/oauth/callback?state='.$state->state.'&code=test-code');

        $response->assertRedirect('http://frontend.test/dashboard/calendar?attached=1');

        $this->assertDatabaseHas('sources', [
            'user_id' => $user->id,
            'external_id' => 'fresh-recall-calendar-id',
            'identity' => $user->email,
            'auth_type' => SourceAuthType::OAUTH2->value,
            'type' => SourceType::GOOGLE_CALENDAR->value,
            'is_connected' => 1,
        ]);

        $source = Source::where([
            'user_id' => $user->id,
            'identity' => $user->email,
            'type' => SourceType::GOOGLE_CALENDAR->value,
        ])->firstOrFail();

        $this->assertFalse($source->trashed());
        $this->assertDatabaseHas('source_oauths', [
            'source_id' => $source->id,
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'fresh-refresh-token',
            'email' => $user->email,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://us-west-2.recall.ai/api/v2/calendars/';
        });
    }

    private function mockGoogleOAuthCallback(OauthDTO $oauthDTO): void
    {
        $mock = Mockery::mock(GoogleOAuthService::class);
        $mock->shouldReceive('callback')
            ->once()
            ->andReturn($oauthDTO);

        $this->app->instance(GoogleOAuthService::class, $mock);
    }
}
