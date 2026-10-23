<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Services\Platforms\GoogleOAuthService;
use App\Services\Platforms\MetaOAuthService;
use App\Services\Platforms\OAuthException;
use App\Services\Scheduling\ScheduleService;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocialAccountController extends Controller
{
    public function __construct(
        private readonly MetaOAuthService $meta,
        private readonly GoogleOAuthService $google,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(): View
    {
        return view('accounts.index', [
            'accounts' => SocialAccount::with(['postingRule', 'parent'])->withCount(['scheduledPosts as failed_count' => fn ($q) => $q->where('status', 'failed')])
                ->orderBy('platform')->orderBy('name')->get(),
            'metaConfigured' => $this->meta->isConfigured(),
            'googleConfigured' => config('reelflow.youtube_enabled') && $this->google->isConfigured(),
            'youtubeEnabled' => (bool) config('reelflow.youtube_enabled'),
            'metaScopes' => config('reelflow.meta.scopes'),
            'googleScopes' => config('reelflow.google.scopes'),
        ]);
    }

    public function connectMeta(Request $request): RedirectResponse
    {
        abort_unless($this->meta->isConfigured(), 404, 'Meta app credentials are not configured.');

        return redirect()->away($this->meta->authorizationUrl($this->newState($request, 'meta')));
    }

    public function metaCallback(Request $request): RedirectResponse
    {
        if ($error = $this->callbackError($request, 'meta')) {
            return redirect()->route('accounts.index')->withErrors(['oauth' => $error]);
        }

        try {
            $key = $this->meta->handleCallback((string) $request->query('code'));
        } catch (OAuthException $e) {
            return redirect()->route('accounts.index')->withErrors(['oauth' => $e->getMessage()]);
        }

        return redirect()->route('accounts.meta.select', ['key' => $key]);
    }

    public function metaSelect(string $key): View|RedirectResponse
    {
        try {
            return view('accounts.meta-select', ['key' => $key] + $this->meta->pendingSelection($key));
        } catch (OAuthException $e) {
            return redirect()->route('accounts.index')->withErrors(['oauth' => $e->getMessage()]);
        }
    }

    public function metaStore(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate([
            'pages' => ['nullable', 'array'], 'pages.*' => ['string', 'max:64'],
            'instagram' => ['nullable', 'array'], 'instagram.*' => ['string', 'max:64'],
        ]);

        try {
            $accounts = $this->meta->connect($key, $data['pages'] ?? [], $data['instagram'] ?? [], $request->user()->id);
        } catch (OAuthException $e) {
            return redirect()->route('accounts.index')->withErrors(['oauth' => $e->getMessage()]);
        }

        return redirect()->route('accounts.index')->with('status', count($accounts).' account(s) connected. Posting rules are created disabled — review and enable them.');
    }

    public function connectGoogle(Request $request): RedirectResponse
    {
        abort_unless(config('reelflow.youtube_enabled') && $this->google->isConfigured(), 404, 'YouTube is not enabled.');

        $verifier = Str::random(64);
        $state = $this->newState($request, 'google');
        $verifiers = collect($request->session()->get('oauth.google.verifiers', []))
            ->only(array_keys($request->session()->get('oauth.google.states', [])))
            ->put($state, $verifier)
            ->all();
        $request->session()->put('oauth.google.verifiers', $verifiers);

        return redirect()->away($this->google->authorizationUrl($state, $verifier));
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        if ($error = $this->callbackError($request, 'google')) {
            return redirect()->route('accounts.index')->withErrors(['oauth' => $error]);
        }

        $verifiers = (array) $request->session()->get('oauth.google.verifiers', []);
        $verifier = (string) ($verifiers[(string) $request->query('state')] ?? '');
        unset($verifiers[(string) $request->query('state')]);
        $request->session()->put('oauth.google.verifiers', $verifiers);

        try {
            $accounts = $this->google->handleCallback((string) $request->query('code'), $verifier, $request->user()->id);
        } catch (OAuthException $e) {
            return redirect()->route('accounts.index')->withErrors(['oauth' => $e->getMessage()]);
        }

        return redirect()->route('accounts.index')->with('status', 'YouTube channel connected: '.collect($accounts)->pluck('name')->implode(', ').'. Review and enable its posting rule.');
    }

    public function pause(SocialAccount $account): RedirectResponse
    {
        Gate::authorize('update', $account);
        if ($account->status === AccountStatus::Connected) {
            $account->update(['status' => AccountStatus::Paused, 'status_reason' => 'Paused by user']);
            $this->activity->log('account.paused', $account, 'Paused by user');
        }

        return back()->with('status', $account->name.' paused. Scheduled posts will wait.');
    }

    public function resume(SocialAccount $account, ScheduleService $schedule): RedirectResponse
    {
        Gate::authorize('update', $account);
        if ($account->status === AccountStatus::Paused || $account->paused_until) {
            $account->update(['status' => AccountStatus::Connected, 'status_reason' => null, 'paused_until' => null]);
            $cleared = $schedule->clearOverdue($account);
            $this->activity->log('account.resumed', $account, "Resumed; {$cleared} overdue unattempted post(s) released for re-planning");
        }

        return back()->with('status', $account->name.' resumed.');
    }

    public function retryFailed(SocialAccount $account, ScheduleService $schedule): RedirectResponse
    {
        Gate::authorize('update', $account);
        $count = $schedule->retryFailedForAccount($account);

        return back()->with('status', "{$count} failed post(s) queued for retry.");
    }

    public function disconnect(SocialAccount $account): RedirectResponse
    {
        Gate::authorize('delete', $account);

        if ($account->platform === Platform::YouTube) {
            $this->google->revoke($account);
        }

        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => AccountStatus::Disconnected,
            'status_reason' => 'Disconnected by user',
        ])->save();
        $account->postingRule?->update(['enabled' => false]);
        $this->activity->log('account.disconnected', $account, 'Tokens deleted');

        $note = $account->platform === Platform::YouTube
            ? 'Access was revoked at Google.'
            : 'To fully revoke access, also remove the app under Facebook Settings → Business Integrations.';

        return back()->with('status', "{$account->name} disconnected. {$note}");
    }

    private function newState(Request $request, string $provider): string
    {
        $state = Str::random(40);
        // Keep the last few pending states so a second click or a second tab
        // does not invalidate the first one.
        $pending = collect($request->session()->get("oauth.{$provider}.states", []))
            ->filter(fn ($at) => now()->timestamp - (int) $at <= 900)
            ->put($state, now()->timestamp)
            ->sortDesc()
            ->take(5)
            ->all();
        $request->session()->put("oauth.{$provider}.states", $pending);

        return $state;
    }

    /**
     * Validate the one-time OAuth state and surface provider errors.
     */
    private function callbackError(Request $request, string $provider): ?string
    {
        $pending = (array) $request->session()->get("oauth.{$provider}.states", []);
        $state = (string) $request->query('state');
        $issuedAt = null;
        foreach ($pending as $candidate => $at) {
            if (hash_equals((string) $candidate, $state)) {
                $issuedAt = (int) $at;
            }
        }

        if ($issuedAt !== null) {
            // Single use.
            unset($pending[$state]);
            $request->session()->put("oauth.{$provider}.states", $pending);
        }

        if ($request->filled('error')) {
            return 'Authorisation was cancelled or denied ('.Str::limit((string) ($request->query('error_description') ?? $request->query('error')), 200).').';
        }
        if ($issuedAt === null) {
            return 'This sign-in link has expired or was already used. Click Connect again (once) and finish in the same browser tab.';
        }
        if (now()->timestamp - $issuedAt > 900) {
            return 'The authorisation request expired. Please try again.';
        }
        if (! $request->filled('code')) {
            return 'No authorisation code was returned.';
        }

        return null;
    }
}
