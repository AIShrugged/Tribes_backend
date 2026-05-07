<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ForgotPasswordRequest;
use App\Http\Requests\API\v1\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PasswordResetService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;

#[Group('Authentication', 'Registration, login, email verification, and personal API token management.')]
class PasswordResetController extends Controller
{
    #[Endpoint(title: 'Forgot password', description: 'Send a password reset link to the given email address. Always returns 200 regardless of whether the email exists.')]
    #[BodyParameter('email', 'User email address.', required: true, type: 'string', example: 'alice@example.com')]
    #[Response(200, 'Reset link sent (or silently ignored if email not found).')]
    public function forgot(ForgotPasswordRequest $request, PasswordResetService $service): ApiResponse
    {
        $service->sendResetLink($request->getEmail());

        return ApiResponse::success('If that email address is in our system, you will receive a password reset link shortly.');
    }

    #[Endpoint(title: 'Reset password', description: 'Reset the user password using the token received by email.')]
    #[BodyParameter('token', 'Password reset token from the email link.', required: true, type: 'string', example: 'abc123xyz')]
    #[BodyParameter('password', 'New password (min 8 characters).', required: true, type: 'string', example: 'newSecret123')]
    #[BodyParameter('password_confirmation', 'Must match the password field.', required: true, type: 'string', example: 'newSecret123')]
    #[Response(200, 'Password reset successfully.')]
    #[Response(422, 'Invalid or expired token.')]
    public function reset(ResetPasswordRequest $request, PasswordResetService $service): ApiResponse
    {
        $success = $service->resetPassword($request->getToken(), $request->getPassword());

        if (!$success) {
            return ApiResponse::error('This password reset link is invalid or has expired.', status: 422);
        }

        return ApiResponse::success('Your password has been reset successfully.');
    }
}
