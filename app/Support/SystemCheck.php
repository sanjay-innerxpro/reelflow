<?php

namespace App\Support;

use App\Models\SocialAccount;
use App\Models\SyncDevice;
use App\Services\Ai\AiProviderFactory;
use App\Services\Platforms\GoogleOAuthService;
use App\Services\Platforms\MetaOAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Production readiness and runtime health checks, shared by
 * `php artisan reels:doctor`, the dashboard and the /health endpoint.
 *
 * Missing platform/AI/mail credentials are warnings (the app runs and says
 * what is missing); broken infrastructure is a failure.
 */
class SystemCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const SCHEDULER_KEY = 'reelflow:heartbeat:scheduler';

    public function __construct(
        private readonly Settings $settings,
        private readonly AiProviderFactory $ai,
        private readonly MetaOAuthService $meta,
        private readonly GoogleOAuthService $google,
    ) {}

    /**
     * Infrastructure the app cannot work without (used by /health).
     *
     * @return array<string, array{status: string, label: string, message: string}>
     */
    public function runtime(): array
    {
        return [
            'database' => $this->check('Database', function () {
                DB::select('select 1');

                return [self::OK, 'Connected'];
            }),
            'cache' => $this->check('Cache / Redis', function () {
                $key = 'reelflow:check:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $ok ? [self::OK, 'Read/write OK ('.config('cache.default').')'] : [self::FAIL, 'Cache did not return the stored value'];
            }),
            'scheduler' => $this->heartbeat('Scheduler (cron)', self::SCHEDULER_KEY, 3, 'Add the cron entry: * * * * * php artisan schedule:run'),
            'workers' => $this->heartbeat('Queue workers', 'reelflow:heartbeat:worker:default', 12, 'Start Horizon (php artisan horizon) or queue:work for the "redis" connection'),
            'workers_long' => $this->heartbeat('Publishing workers', 'reelflow:heartbeat:worker:long', 12, 'Start Horizon or queue:work for the "'.config('queue.long_connection').'" connection'),
        ];
    }

    /**
     * Everything, including configuration (used by reels:doctor and the dashboard).
     *
     * @return array<string, array{status: string, label: string, message: string}>
     */
    public function all(): array
    {
        $production = app()->isProduction();

        $checks = $this->runtime() + [
            'app_key' => $this->check('APP_KEY', fn () => filled(config('app.key')) ? [self::OK, 'Set (back it up: tokens are encrypted with it)'] : [self::FAIL, 'Run php artisan key:generate']),
            'debug' => $this->check('Debug mode', fn () => config('app.debug') && $production ? [self::FAIL, 'APP_DEBUG must be false in production'] : [self::OK, config('app.debug') ? 'On (development)' : 'Off']),
            'https' => $this->check('HTTPS URL', fn () => str_starts_with((string) config('app.url'), 'https://')
                ? [self::OK, config('app.url')]
                : [$production ? self::FAIL : self::WARN, 'APP_URL must be https:// in production (Meta OAuth requires it): '.config('app.url')]),
            'timezone' => $this->check('Stored timezone', fn () => config('app.timezone') === 'UTC' ? [self::OK, 'UTC'] : [self::FAIL, 'config app.timezone must be UTC']),
            'ffmpeg' => $this->check('FFmpeg / FFprobe', function () {
                foreach (['ffmpeg_path' => '-version', 'ffprobe_path' => '-version'] as $key => $arg) {
                    $process = new Process([config("reelflow.media.$key"), $arg]);
                    $process->setTimeout(10);
                    $process->run();
                    if (! $process->isSuccessful()) {
                        return [self::FAIL, config("reelflow.media.$key").' is not runnable (sudo apt install ffmpeg, or set FFMPEG_PATH/FFPROBE_PATH)'];
                    }
                }

                return [self::OK, 'Available'];
            }),
            'storage' => $this->check('Video storage', function () {
                $disk = Storage::disk(config('reelflow.storage.disk'));
                $key = 'healthchecks/'.Str::random(12).'.txt';
                $disk->put($key, 'ok');
                $disk->delete($key);
                $free = config('reelflow.storage.disk') === 'videos' ? @disk_free_space(config('filesystems.disks.videos.root')) : false;
                $freeText = $free !== false && $free !== null ? ', '.Bytes::human((int) $free).' free' : '';
                $lowSpace = $free !== false && $free !== null && $free < 2 * 1024 ** 3;

                return [$lowSpace ? self::WARN : self::OK, 'Writable ('.config('reelflow.storage.disk').$freeText.')'.($lowSpace ? ' — less than 2 GB left' : '')];
            }),
            'mail' => $this->check('Email', function () use ($production) {
                $recipients = $this->settings->recipients();
                if (config('mail.default') === 'log' || config('mail.default') === 'array') {
                    return [$production ? self::WARN : self::OK, 'MAIL_MAILER='.config('mail.default').' (emails are not sent) — add Gmail App Password SMTP credentials'];
                }
                if (config('mail.default') === 'smtp' && blank(config('mail.mailers.smtp.password'))) {
                    return [self::WARN, 'SMTP password missing (MAIL_PASSWORD)'];
                }

                return $recipients ? [self::OK, 'SMTP to '.implode(', ', $recipients)] : [self::WARN, 'No notification recipients (Email settings)'];
            }),
            'ai' => $this->check('AI captions', fn () => match (true) {
                config('reelflow.ai.provider') === 'none' => [self::WARN, 'Disabled (AI_PROVIDER=none): captions must be written manually'],
                ! $this->ai->isConfigured() => [self::WARN, 'AI_PROVIDER='.config('reelflow.ai.provider').' is missing AI_API_KEY / AI_MODEL'],
                default => [self::OK, config('reelflow.ai.provider').($this->ai->isFree() === false ? ' (paid)' : ' (free)')],
            }),
            'meta' => $this->check('Meta app (Facebook/Instagram)', fn () => $this->meta->isConfigured()
                ? [self::OK, 'Configured, callback '.config('reelflow.meta.redirect_uri')]
                : [self::WARN, 'Add META_APP_ID, META_APP_SECRET, META_REDIRECT_URI']),
            'google' => $this->check('Google app (YouTube)', fn () => $this->google->isConfigured()
                ? [self::OK, 'Configured, callback '.config('reelflow.google.redirect_uri')]
                : [self::WARN, 'Add GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI']),
            'accounts' => $this->check('Connected accounts', function () {
                $connected = SocialAccount::where('status', 'connected')->count();
                $enabled = SocialAccount::where('status', 'connected')->whereHas('postingRule', fn ($q) => $q->where('enabled', true))->count();
                $problems = SocialAccount::whereIn('status', ['expired', 'revoked', 'ineligible'])->count();

                return match (true) {
                    $problems > 0 => [self::WARN, "{$problems} account(s) need reconnecting"],
                    $connected === 0 => [self::WARN, 'No accounts connected yet'],
                    $enabled === 0 => [self::WARN, "{$connected} connected, but no posting rule is enabled"],
                    default => [self::OK, "{$connected} connected, {$enabled} auto-posting"],
                };
            }),
            'devices' => $this->check('Sync devices', function () {
                $active = SyncDevice::where('status', 'active')->get();

                return match (true) {
                    $active->isEmpty() => [self::WARN, 'No sync device registered'],
                    $active->contains(fn ($d) => $d->isOnline()) => [self::OK, $active->filter->isOnline()->count().' online'],
                    default => [self::WARN, 'All sync agents offline (uploaded videos still publish)'],
                };
            }),
            'failed_jobs' => $this->check('Failed jobs', function () {
                $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

                return $count ? [self::WARN, "{$count} in the last 24 hours"] : [self::OK, 'None in the last 24 hours'];
            }),
        ];

        return $checks;
    }

    /**
     * @param  array<string, array{status: string}>  $checks
     */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    public static function beat(string $key): void
    {
        Cache::forever($key, now()->toIso8601String());
    }

    /**
     * @return array{status: string, label: string, message: string}
     */
    private function heartbeat(string $label, string $key, int $maxMinutes, string $fix): array
    {
        return $this->check($label, function () use ($key, $maxMinutes, $fix) {
            $last = Cache::get($key);
            if (! $last) {
                return [self::FAIL, 'Never seen. '.$fix];
            }
            $age = (int) CarbonImmutable::parse($last)->diffInMinutes(now());

            return $age <= $maxMinutes
                ? [self::OK, "Last seen {$age} min ago"]
                : [self::FAIL, "Last seen {$age} min ago. ".$fix];
        });
    }

    /**
     * @param  callable(): array{0: string, 1: string}  $callback
     * @return array{status: string, label: string, message: string}
     */
    private function check(string $label, callable $callback): array
    {
        try {
            [$status, $message] = $callback();
        } catch (Throwable $e) {
            [$status, $message] = [self::FAIL, SecretRedactor::truncate($e->getMessage(), 200)];
        }

        return ['status' => $status, 'label' => $label, 'message' => $message];
    }
}
