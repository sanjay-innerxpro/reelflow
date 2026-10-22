<?php

namespace App\Providers;

use App\Listeners\LogNotificationDelivery;
use App\Services\Ai\AiProviderFactory;
use App\Services\Media\FfmpegMediaToolkit;
use App\Services\Media\MediaToolkit;
use App\Support\ActivityLogger;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
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
        $this->app->singleton(ActivityLogger::class);
        $this->app->singleton(AiProviderFactory::class);
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
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
}
