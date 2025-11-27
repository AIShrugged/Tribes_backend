<?php

use App\Exceptions\AppException;
use App\Http\Middleware\Authenticate;
use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(ThrottleRequests::class);
        $middleware->api(SubstituteBindings::class);
        $middleware->alias(['auth' => Authenticate::class]);
        $middleware->trustProxies(['*'],
            SymfonyRequest::HEADER_X_FORWARDED_FOR |
            SymfonyRequest::HEADER_X_FORWARDED_HOST |
            SymfonyRequest::HEADER_X_FORWARDED_PORT |
            SymfonyRequest::HEADER_X_FORWARDED_PROTO |
            SymfonyRequest::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request, Throwable $e) => $request->is('api/*')
        );

        $exceptions->render(function (AppException $e, Request $request) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: $e->getCode(),
                meta: [
                    'error_code' => $e->getErrorCode(),
                ]
            );
        });

    })->create();
