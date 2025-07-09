<?php

use App\Http\Controllers\API\v1\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {

    Route::post('auth/register', [AuthController::class, 'register'])
        ->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::group(['prefix' => 'users'], function () {
            Route::get('/me', function (Request $request) {
                return $request->user();
            });
        });
    });
});
