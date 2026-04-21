<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UpdateUserPreferencesRequest;
use App\Http\Responses\ApiResponse;

class UserPreferencesController extends Controller
{
    /**
     * Update the authenticated user's UI preferences.
     */
    public function update(UpdateUserPreferencesRequest $request): ApiResponse
    {
        $user = $request->user();
        $user->update(['preferences' => $request->getPreferences()]);

        return ApiResponse::success(data: [
            'preferences' => $user->preferences,
        ]);
    }
}