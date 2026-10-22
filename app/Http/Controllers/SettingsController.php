<?php

namespace App\Http\Controllers;

use App\Models\AiGeneration;
use App\Models\NotificationLog;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\CaptionPrompt;
use App\Services\Notifications\NotificationService;
use App\Support\ActivityLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function email(): View
    {
        $mailer = config('mail.default');

        return view('settings.email', [
            'settings' => $this->settings->all(),
            'mail' => [
                'mailer' => $mailer,
                'host' => config("mail.mailers.{$mailer}.host"),
                'port' => config("mail.mailers.{$mailer}.port"),
                'scheme' => config("mail.mailers.{$mailer}.scheme"),
                'username_set' => filled(config("mail.mailers.{$mailer}.username")),
                'password_set' => filled(config("mail.mailers.{$mailer}.password")),
                'from' => config('mail.from.address'),
            ],
            'logs' => NotificationLog::latest()->limit(25)->get(),
        ]);
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipients' => ['required', 'string', 'max:1000'],
            'daily_summary_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $recipients = collect(preg_split('/[\s,;]+/', $data['recipients'], -1, PREG_SPLIT_NO_EMPTY))->unique()->values();
        $invalid = $recipients->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($invalid->isNotEmpty()) {
            return back()->withErrors(['recipients' => 'Invalid address: '.$invalid->implode(', ')])->withInput();
        }

        $this->settings->set([
            'notifications.recipients' => $recipients->all(),
            'notifications.daily_summary_time' => $data['daily_summary_time'],
            'notifications.on_success' => $request->boolean('on_success'),
            'notifications.on_failure' => $request->boolean('on_failure'),
            'notifications.on_auth_problem' => $request->boolean('on_auth_problem'),
            'notifications.on_device_offline' => $request->boolean('on_device_offline'),
            'notifications.on_upload_failures' => $request->boolean('on_upload_failures'),
            'notifications.daily_summary' => $request->boolean('daily_summary'),
        ]);
        $this->activity->log('settings.email', null, 'Email notification settings updated');

        return back()->with('status', 'Email settings saved.');
    }

    public function testEmail(Request $request, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        try {
            $notifications->sendTest($data['email']);
        } catch (Throwable $e) {
            return back()->withErrors(['test' => 'Sending failed: '.$e->getMessage()]);
        }

        $note = config('mail.default') === 'log' ? ' (MAIL_MAILER=log: the message was written to the log, not sent)' : '';

        return back()->with('status', "Test email sent to {$data['email']}{$note}.");
    }

    /**
     * Connect and authenticate to the SMTP server without sending anything.
     */
    public function validateSmtp(): RedirectResponse
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        if (! $transport instanceof SmtpTransport) {
            return back()->withErrors(['smtp' => 'The active mailer ('.config('mail.default').') is not SMTP; nothing to validate.']);
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (Throwable $e) {
            return back()->withErrors(['smtp' => 'SMTP connection failed: '.$e->getMessage()]);
        }

        return back()->with('status', 'SMTP connection and authentication succeeded.');
    }

    public function ai(AiProviderFactory $ai, CaptionPrompt $prompt): View
    {
        [$systemPrompt, $version] = rescue(fn () => $prompt->system(), ['', 'unavailable'], false);

        return view('settings.ai', [
            'settings' => $this->settings->all(),
            'provider' => config('reelflow.ai.provider'),
            'model' => config('reelflow.ai.model') ?: config('reelflow.ai.presets.'.config('reelflow.ai.provider').'.model'),
            'configured' => $ai->isConfigured(),
            'isFree' => $ai->isFree(),
            'transcription' => config('reelflow.ai.transcription.provider'),
            'promptVersion' => $version,
            'defaultPrompt' => $systemPrompt,
            'spentToday' => (float) AiGeneration::where('created_at', '>=', now()->startOfDay())->sum('cost_usd'),
            'budget' => config('reelflow.ai.daily_budget_usd'),
            'recent' => AiGeneration::with('video')->latest()->limit(15)->get(),
        ]);
    }

    public function updateAi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(['auto', 'en', 'hi', 'hinglish'])],
            'audience' => ['nullable', 'string', 'max:300'],
            'tone' => ['nullable', 'string', 'max:200'],
            'brand_context' => ['nullable', 'string', 'max:1000'],
            'custom_prompt' => ['nullable', 'string', 'max:8000'],
            'custom_prompt_version' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $this->settings->set([
            'ai.language' => $data['language'],
            'ai.audience' => $data['audience'] ?? '',
            'ai.tone' => $data['tone'] ?? '',
            'ai.brand_context' => $data['brand_context'] ?? '',
            'ai.custom_prompt' => filled($data['custom_prompt'] ?? null) ? $data['custom_prompt'] : null,
            'ai.custom_prompt_version' => $data['custom_prompt_version'] ?? null,
            'ai.auto_generate' => $request->boolean('auto_generate'),
            'ai.transcribe_audio' => $request->boolean('transcribe_audio'),
        ]);
        $this->activity->log('settings.ai', null, 'AI settings updated');

        return back()->with('status', 'AI settings saved.');
    }
}
