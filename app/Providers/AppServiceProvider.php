<?php

namespace App\Providers;

use App\Database\RetryingMariaDbConnector;
use App\Database\RetryingMySqlConnector;
use App\Listeners\LogNotificationDelivery;
use App\Services\Ai\AiProviderFactory;
use App\Services\Media\FfmpegMediaToolkit;
use App\Services\Media\MediaToolkit;
use App\Support\ActivityLogger;
use App\Support\Credentials;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaToolkit::class, FfmpegMediaToolkit::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(Credentials::class);
        $this->app->singleton(ActivityLogger::class);
        $this->app->singleton(AiProviderFactory::class);
        $this->app->bind('db.connector.mysql', RetryingMySqlConnector::class);
        $this->app->bind('db.connector.mariadb', RetryingMariaDbConnector::class);
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Credentials saved in Admin → Credentials take precedence over .env.
        // Skipped while the config cache is built, so they never end up in
        // the plaintext bootstrap/cache/config.php.
        if (! $this->isCachingConfig()) {
            $credentials = $this->app->make(Credentials::class);
            $credentials->apply();
            // A worker that started while the database was unreachable
            // picks the saved values up before its next job.
            Event::listen(JobProcessing::class, function () use ($credentials) {
                if (! $credentials->isLoaded()) {
                    $credentials->apply();
                }
            });
        }

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(600)
            ->by('device:'.($request->attributes->get('device')?->id ?? $request->ip())));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        Event::listen(NotificationSent::class, [LogNotificationDelivery::class, 'handleSent']);
        Event::listen(NotificationFailed::class, [LogNotificationDelivery::class, 'handleFailed']);
    }

    private function isCachingConfig(): bool
    {
        return $this->app->runningInConsole()
            && array_intersect($_SERVER['argv'] ?? [], ['config:cache', 'optimize']) !== [];
    }
}
