<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::macro('withProxy', function () {
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
