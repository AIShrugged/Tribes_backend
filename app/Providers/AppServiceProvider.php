<?php

namespace App\Providers;

use App\Models\IssueAttachment;
use App\Models\Organization;
use App\Observers\OrganizationObserver;
use App\Policies\IssueAttachmentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->scoped(\App\Services\Agent\Tools\ToolRegistry::class);
        $this->app->scoped(\App\Services\Agent\AgentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(IssueAttachment::class, IssueAttachmentPolicy::class);
        Organization::observe(OrganizationObserver::class);

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
                ),
            ]);
        });

        // Throttle digest LLM dispatches to respect OpenRouter ~200 RPM cap with headroom.
        RateLimiter::for('openrouter-digests', fn () => Limit::perMinute(60));
    }
}
