<?php

use App\Http\Controllers\API\v1\AuthController;
use App\Http\Controllers\API\v1\CalendarEventController;
use App\Http\Controllers\API\v1\GoogleCalendarController;
use App\Http\Controllers\API\v1\RecallWebhookController;
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

    Route::get('recall/webhook', [RecallWebhookController::class, 'webhook']);

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
            Route::get('/{event_id}', [CalendarEventController::class, 'show'])->name('calendar-events.show');
        });
    });
});
