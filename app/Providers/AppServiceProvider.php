<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Policies\ChatPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Email Provider
        $this->app->singleton(\App\Contracts\EmailProviderInterface::class, function ($app) {
            $provider = config('email.default_provider');
            $config = config("email.providers.{$provider}");

            return match ($provider) {
                'unisender_go' => new \App\Services\Email\Providers\UnisenderGoProvider(
                    apiKey: $config['api_key'],
                    apiUrl: $config['api_url'],
                ),
                default => throw new \InvalidArgumentException("Unknown email provider: {$provider}"),
            };
        });

        // Register Email Service
        $this->app->singleton(\App\Services\Email\EmailService::class);

        // Agent singletons — ToolRegistry must be shared between AgentService and controllers
        $this->app->singleton(\App\Services\Agent\Tools\ToolRegistry::class);
        $this->app->singleton(\App\Services\Agent\AgentService::class);

        // Telegram Bot API — singleton so it can be mocked in tests
        $this->app->singleton(\Telegram\Bot\Api::class, fn() => new \Telegram\Bot\Api(config('telegram.bot_token')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Route model binding: {chat} param resolves to Conversation (routes stay /chats for backward compat)
        Route::model('chat', Conversation::class);

        // Register ChatPolicy for Conversation model
        Gate::policy(Conversation::class, ChatPolicy::class);

        Http::macro('withProxy', function () {
            if (! config('proxy.enabled', true)) {
                return Http::withOptions([]);
            }

            return Http::withOptions([
                'proxy' => sprintf(
                    'http://%s:%s@%s:%s',
                    urlencode(config('proxy.user')),
                    urlencode(config('proxy.pass')),
                    config('proxy.host'),
                    config('proxy.port')
                )
            ]);
        });
    }
}
