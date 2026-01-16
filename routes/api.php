<?php

use App\Http\Controllers\API\v1\AuthController;
use App\Http\Controllers\API\v1\BotController;
use App\Http\Controllers\API\v1\CalendarEventController;
use App\Http\Controllers\API\v1\FollowupController;
use App\Http\Controllers\API\v1\GoogleCalendarController;
use App\Http\Controllers\API\v1\MethodologyController;
use App\Http\Controllers\API\v1\ParticipantController;
use App\Http\Controllers\API\v1\ProfileController;
use App\Http\Controllers\API\v1\OrganizationController;
use App\Http\Controllers\API\v1\RecallWebhookController;
use App\Http\Controllers\API\v1\SourceController;
use App\Http\Controllers\API\v1\TeamController;
use App\Http\Controllers\API\v1\TeamUserController;
use App\Http\Controllers\API\v1\TranscriptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {

    Route::post('auth/register', [AuthController::class, 'register'])
        ->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::get('google/oauth/callback', [GoogleCalendarController::class, 'callback'])
        ->name('google.oauth.callback');

    Route::post('recall/webhook', [RecallWebhookController::class, 'webhook']);

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::group(['prefix' => 'users'], function () {
            Route::get('/me', function (Request $request) {
                return $request->user();
            });
        });

        Route::group(['prefix' => 'google'], function () {
            Route::post('oauth', [GoogleCalendarController::class, 'attach']);
        });

        Route::group(['prefix' => 'calendar-events'], function () {
            Route::get('/', [CalendarEventController::class, 'index'])->name('calendar-events.index');
            Route::get('/{calendar_event_id}', [CalendarEventController::class, 'show'])->name('calendar-events.show');

            Route::post('/{calendar_event_id}/bot/require', [BotController::class, 'require']);

            Route::get('/{calendar_event_id}/participants', [ParticipantController::class, 'index'])
                ->name('calendar-events.participants.index');

            Route::post('/{calendar_event_id}/participants/{participant_id}/set-profile',
                [ParticipantController::class, 'setProfile'])
                ->name('calendar-events.participants.set-profile');

            Route::get('/{calendar_event_id}/profiles', [ProfileController::class, 'index']);

            Route::get('/{calendar_event_id}/transcript', [TranscriptController::class, 'index']);

            Route::get('/{calendar_event_id}/followups', [FollowupController::class, 'index'])
                ->name('calendar-events.followups.index');

            Route::post('/{calendar_event_id}/followups/generate', [FollowupController::class, 'generate']);
        });

        Route::get('/followups/{followup_id}', [FollowupController::class, 'show'])
            ->name('followups.show');

        Route::get('organizations/{organization}/teams', [TeamController::class, 'index']);
        Route::apiResource('teams', TeamController::class)
            ->except(['index']);
        Route::get('teams/{team}/methodologies/active', [TeamController::class, 'activeMethodology']);
        Route::post('methodologies/assign', [TeamController::class, 'assignMethodologyForTeam']);

        Route::apiResource('teams.users', TeamUserController::class)
            ->only(['index', 'show']);
        Route::post('teams/{team}/users/{user}/kick', [TeamUserController::class, 'kick']);

        Route::apiResource('organizations', OrganizationController::class);

        Route::get('organizations/{organization}/methodologies', [MethodologyController::class, 'index']);
        Route::apiResource('methodologies', MethodologyController::class)
            ->except(['index']);

        Route::get('/sources', [SourceController::class, 'index']);
    });
});
