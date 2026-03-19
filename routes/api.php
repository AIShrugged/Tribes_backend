<?php

use App\Http\Controllers\API\v1\AuthController;
use App\Http\Controllers\API\v1\AgentMemoryController;
use App\Http\Controllers\API\v1\AgentProfileController;
use App\Http\Controllers\API\v1\AgentTaskController;
use App\Http\Controllers\API\v1\BotController;
use App\Http\Controllers\API\v1\UserIdentityController;
use App\Http\Controllers\API\v1\CalendarEventController;
use App\Http\Controllers\API\v1\ChatArtifactController;
use App\Http\Controllers\API\v1\ChatController;
use App\Http\Controllers\API\v1\ChatMessageController;
use App\Http\Controllers\API\v1\EmailVerificationController;
use App\Http\Controllers\API\v1\FollowupController;
use App\Http\Controllers\API\v1\FollowupExportController;
use App\Http\Controllers\API\v1\GoogleCalendarController;
use App\Http\Controllers\API\v1\MethodologyController;
use App\Http\Controllers\API\v1\ParticipantController;
use App\Http\Controllers\API\v1\ProfileController;
use App\Http\Controllers\API\v1\OrganizationController;
use App\Http\Controllers\API\v1\RecallWebhookController;
use App\Http\Controllers\API\v1\SandboxToolGatewayController;
use App\Http\Controllers\API\v1\SourceController;
use App\Http\Controllers\API\v1\TeamController;
use App\Http\Controllers\API\v1\TeamInviteController;
use App\Http\Controllers\API\v1\TeamUserController;
use App\Http\Controllers\API\v1\TelegramBotController;
use App\Http\Controllers\API\v1\MeetingSummaryController;
use App\Http\Controllers\API\v1\InsightController;
use App\Http\Controllers\API\v1\MeetingTaskController;
use App\Http\Controllers\API\v1\DashboardController;
use App\Http\Controllers\API\v1\DemoController;
use App\Http\Controllers\API\v1\TranscriptController;
use App\Http\Controllers\API\v1\UserController;
use App\Http\Controllers\API\v1\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {

    Route::post('auth/register', [AuthController::class, 'register'])
        ->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');
    Route::get('auth/tokens', [AuthController::class, 'tokens'])
        ->middleware('auth:sanctum');
    Route::post('auth/tokens', [AuthController::class, 'createToken'])
        ->middleware('auth:sanctum');
    Route::delete('auth/tokens/{tokenId}', [AuthController::class, 'revokeToken'])
        ->middleware('auth:sanctum');

    Route::get('auth/email/verify/{token}', [EmailVerificationController::class, 'verify'])
        ->name('auth.email.verify');
    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth:sanctum', 'throttle:6,1'])
        ->name('auth.email.resend');

    Route::get('google/oauth/callback', [GoogleCalendarController::class, 'callback'])
        ->name('google.oauth.callback');

    Route::post('recall/webhook', [RecallWebhookController::class, 'webhook']);

    // Telegram bot webhook
    Route::post('telegram/webhook', [TelegramBotController::class, 'webhook']);
    Route::post('internal/agent-task-runs/{run}/tool-calls', [SandboxToolGatewayController::class, 'store']);
    Route::post('internal/agent-task-runs/{run}/llm-completions', [SandboxToolGatewayController::class, 'complete']);

    Route::get('invites/accept/{token}', [TeamInviteController::class, 'accept'])
        ->name('invites.accept');

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::group(['prefix' => 'users'], function () {
            Route::get('/me', function (Request $request) {
                return $request->user();
            });
            Route::patch('/me', [UserController::class, 'update']);

            Route::group(['prefix' => 'me'], function () {
                Route::get('identities', [UserIdentityController::class, 'index']);
                Route::post('identities', [UserIdentityController::class, 'link']);
                Route::delete('identities/{profile}', [UserIdentityController::class, 'unlink']);
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

            Route::post(
                '/{calendar_event_id}/participants/{participant_id}/set-profile',
                [ParticipantController::class, 'setProfile']
            )
                ->name('calendar-events.participants.set-profile');

            Route::get('/{calendar_event_id}/profiles', [ProfileController::class, 'index']);

            Route::get('/{calendar_event_id}/transcript', [TranscriptController::class, 'index']);

            Route::get('{calendarEvent}/followup', [FollowupController::class, 'eventShow'])
                ->name('calendar-events.followup');

            Route::post('/{calendar_event_id}/followups/generate', [FollowupController::class, 'generate']);

            Route::get('/{calendar_event_id}/meeting-summary', [MeetingSummaryController::class, 'show'])
                ->name('calendar-events.meeting-summary.show');
            Route::post('/{calendar_event_id}/meeting-summary/generate', [MeetingSummaryController::class, 'generate'])
                ->name('calendar-events.meeting-summary.generate');

            Route::get('/{calendar_event_id}/tasks', [MeetingTaskController::class, 'index'])
                ->name('calendar-events.tasks.index');
            Route::post('/{calendar_event_id}/tasks/generate', [MeetingTaskController::class, 'generate'])
                ->name('calendar-events.tasks.generate');
        });

        Route::get('teams/{team}/followups', [FollowupController::class, 'index'])
            ->name('teams.followups.index');

        Route::get('/followups/{followup}', [FollowupController::class, 'show'])
            ->name('followups.show');

        Route::get('/tasks/{task_id}', [MeetingTaskController::class, 'show'])
            ->name('tasks.show');

        Route::get('organizations/{organization}/teams', [TeamController::class, 'index']);
        Route::apiResource('teams', TeamController::class)
            ->except(['index']);
        Route::get('teams/{team}/methodologies/active', [TeamController::class, 'activeMethodology']);
        Route::post('methodologies/assign', [TeamController::class, 'assignMethodologyForTeam']);

        Route::apiResource('teams.users', TeamUserController::class)
            ->only(['index', 'show']);
        Route::post('teams/{team}/users/{user}/kick', [TeamUserController::class, 'kick']);

        Route::get('teams/{team}/invites', [TeamInviteController::class, 'index']);
        Route::post('teams/{team}/invites', [TeamInviteController::class, 'store']);
        Route::delete('teams/{team}/invites/{invite}', [TeamInviteController::class, 'destroy']);

        Route::apiResource('organizations', OrganizationController::class);
        Route::apiResource('workspaces', WorkspaceController::class);
        Route::get('workspaces/{workspace}/contents', [WorkspaceController::class, 'contents']);
        Route::get('workspaces/{workspace}/file', [WorkspaceController::class, 'readFile']);
        Route::put('workspaces/{workspace}/file', [WorkspaceController::class, 'writeFile']);
        Route::delete('workspaces/{workspace}/file', [WorkspaceController::class, 'deleteFile']);
        Route::post('workspaces/{workspace}/directories', [WorkspaceController::class, 'createDirectory']);
        Route::post('workspaces/{workspace}/permissions', [WorkspaceController::class, 'storePermission']);
        Route::delete('workspaces/{workspace}/permissions/{workspacePermission}', [WorkspaceController::class, 'destroyPermission']);

        Route::get('organizations/{organization}/methodologies', [MethodologyController::class, 'index']);
        Route::apiResource('methodologies', MethodologyController::class)
            ->except(['index']);

        Route::get('/sources', [SourceController::class, 'index']);

        // Wanda Chat
        Route::apiResource('chats', ChatController::class);
        Route::get('chats/{chat}/messages', [ChatMessageController::class, 'index']);
        Route::post('chats/{chat}/messages', [ChatMessageController::class, 'store']);
        Route::get('chats/{chat}/runs/{runUuid}', [ChatMessageController::class, 'showRunStatus']);
        Route::get('chats/{chat}/artifacts', [ChatArtifactController::class, 'index']);

        // Agent profiles
        Route::get('agent-profiles', [AgentProfileController::class, 'index']);
        Route::post('agent-profiles', [AgentProfileController::class, 'store']);
        Route::get('agent-profiles/{agentProfile}', [AgentProfileController::class, 'show']);
        Route::patch('agent-profiles/{agentProfile}', [AgentProfileController::class, 'update']);
        Route::delete('agent-profiles/{agentProfile}', [AgentProfileController::class, 'destroy']);
        Route::post('agent-profiles/{agentProfile}/validate-payload', [AgentProfileController::class, 'validatePayload']);
        Route::get('agent-profiles/{agentProfile}/memories', [AgentMemoryController::class, 'profileIndex']);

        // Agent tasks
        Route::get('agent-tasks', [AgentTaskController::class, 'index']);
        Route::post('agent-tasks', [AgentTaskController::class, 'store']);
        Route::get('agent-tasks/{agentTask}', [AgentTaskController::class, 'show']);
        Route::patch('agent-tasks/{agentTask}', [AgentTaskController::class, 'update']);
        Route::delete('agent-tasks/{agentTask}', [AgentTaskController::class, 'destroy']);
        Route::get('agent-tasks/{agentTask}/memories', [AgentMemoryController::class, 'taskIndex']);

        // Agent memories
        Route::get('agent-memories', [AgentMemoryController::class, 'index']);
        Route::get('agent-memories/{agentMemory}', [AgentMemoryController::class, 'show']);

        // Followup Export
        Route::get('followups/{followup}/export', [FollowupExportController::class, 'export']);

        // Dashboard statistics
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->name('dashboard.index');

        // Demo data generation
        Route::post('demo/seed', [DemoController::class, 'seed']);
        Route::get('demo/status', [DemoController::class, 'status']);
        Route::delete('demo', [DemoController::class, 'destroy']);

        // Insight — user profiling and memory
        Route::group(['prefix' => 'insight'], function () {
            Route::get('profiles/{profile}', [InsightController::class, 'profile'])
                ->name('insight.profile.show');
            Route::delete('profiles/{profile}', [InsightController::class, 'forget'])
                ->name('insight.profile.forget');
            Route::get('profiles/{profile}/short-term', [InsightController::class, 'shortTerm'])
                ->name('insight.profile.short-term');
            Route::get('profiles/{profile}/items', [InsightController::class, 'items'])
                ->name('insight.profile.items');
            Route::get('profiles/{profile}/sources', [InsightController::class, 'sources'])
                ->name('insight.profile.sources');
            Route::get('profiles/{profile}/history', [InsightController::class, 'history'])
                ->name('insight.profile.history');
            Route::get('relationships', [InsightController::class, 'relationship'])
                ->name('insight.relationships');
        });
    });
});
