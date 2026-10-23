<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\ActivityLogger;
use App\Support\Credentials;
use App\Support\SecretRedactor;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Super-admin: the platform credentials (Meta and Google apps, AI keys, the
 * Drive key, SMTP) that override the server's .env. See Credentials.
 */
class CredentialController extends Controller
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly ActivityLogger $activity,
    ) {}

    public function edit(): View
    {
        return view('admin.credentials', [
            'groups' => Credentials::groups(),
            'credentials' => $this->credentials,
            // Never select the value column: the page shows masks only.
            'updated' => SystemSetting::with('updatedBy:id,name')->get(['key', 'updated_by', 'updated_at'])->keyBy('key'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $fields = Credentials::fields();
        $secretKeys = array_keys(array_filter($fields, Credentials::isSecret(...)));

        $rules = ['clear' => ['nullable', 'array'], 'clear.*' => [Rule::in($secretKeys)]];
        $attributes = [];
        foreach (Credentials::groups() as $group) {
            foreach ($group['fields'] as $key => $field) {
                $name = Credentials::inputName($key);
                $rules[$name] = array_merge(['nullable', 'string', 'max:4000'], $this->typeRules($field), $field['rules'] ?? []);
                $attributes[$name] = $group['short'].' '.$field['label'];
            }
        }
        $data = $request->validate($rules, [], $attributes);

        $clear = $data['clear'] ?? [];
        $values = [];
        foreach ($fields as $key => $field) {
            $input = $data[Credentials::inputName($key)] ?? null;
            if (! Credentials::isSecret($field)) {
                $values[$key] = $input; // empty removes the saved value
            } elseif (filled($input)) {
                $values[$key] = $input; // a new secret replaces the saved one
            } elseif (in_array($key, $clear, true)) {
                $values[$key] = null;
            } // else: an empty secret field keeps the saved value
        }

        $changed = $this->credentials->save($values, $request->user()->id);
        if ($changed === []) {
            return back()->with('status', 'Nothing changed.');
        }

        $this->activity->log('settings.credentials', null, 'Credentials updated', ['changed' => $changed]);
        // Long-running queue workers re-read the config only when they start.
        rescue(fn () => Artisan::call('queue:restart'), report: false);

        $labels = array_map(fn ($key) => $attributes[Credentials::inputName($key)], $changed);

        return back()->with('status', 'Saved: '.implode(', ', $labels).'. Queue workers restart to pick up the change.');
    }

    /**
     * Copy what the server's .env sets into the panel (fields not saved yet).
     */
    public function import(Request $request): RedirectResponse
    {
        $imported = $this->credentials->importFromEnv($request->user()->id);
        if ($imported === []) {
            return back()->with('status', 'Nothing to import: every credential .env sets is already saved here.');
        }

        $this->activity->log('settings.credentials', null, 'Credentials imported from .env', ['changed' => $imported]);
        rescue(fn () => Artisan::call('queue:restart'), report: false);

        $groups = Credentials::groups();
        $labels = array_map(function ($key) use ($groups) {
            foreach ($groups as $group) {
                if (isset($group['fields'][$key])) {
                    return $group['short'].' '.$group['fields'][$key]['label'];
                }
            }

            return $key;
        }, $imported);

        return back()->with('status', 'Imported from .env: '.implode(', ', $labels).'.');
    }

    /**
     * Check the saved values against the provider without changing anything.
     */
    public function test(string $group): RedirectResponse
    {
        [$ok, $message] = match ($group) {
            'meta' => $this->testMeta(),
            'google' => $this->testGoogle(),
            'ai' => $this->testAi(),
            'mail' => $this->testMail(),
        };

        // Shown inside the tested section, which the anchor scrolls to.
        return redirect()->to(route('admin.credentials').'#'.$group)
            ->with('credential_test', ['group' => $group, 'ok' => $ok, 'message' => $message]);
    }

    /**
     * @return array<int, mixed>
     */
    private function typeRules(array $field): array
    {
        return match ($field['type'] ?? 'text') {
            'url' => ['url:http,https', 'max:500'],
            'email' => ['email', 'max:255'],
            'select' => [Rule::in(array_map('strval', array_keys($field['options'])))],
            default => [],
        };
    }

    /**
     * An app access token (id|secret) can read the app itself.
     *
     * @return array{0: bool, 1: string}
     */
    private function testMeta(): array
    {
        $id = config('reelflow.meta.app_id');
        $secret = config('reelflow.meta.app_secret');
        if (blank($id) || blank($secret)) {
            return [false, 'Meta: save the App ID and App secret first.'];
        }

        try {
            $response = Http::timeout(20)->get(rtrim(config('reelflow.meta.graph_url'), '/').'/'.config('reelflow.meta.graph_version').'/'.$id, [
                'fields' => 'id,name',
                'access_token' => $id.'|'.$secret,
            ]);
        } catch (Throwable $e) {
            return [false, 'Meta: could not reach the Graph API ('.SecretRedactor::redactString($e->getMessage()).').'];
        }

        if ($response->successful()) {
            return [true, 'Meta accepted the App ID and secret for "'.$response->json('name', 'your app').'". Callback to register: '.config('reelflow.meta.redirect_uri')];
        }

        return [false, 'Meta rejected the App ID or App secret: '.$this->errorText($response)];
    }

    /**
     * Exchanging a made-up code tells a valid client ("invalid_grant") from
     * a wrong ID or secret ("invalid_client") without any side effect.
     *
     * @return array{0: bool, 1: string}
     */
    private function testGoogle(): array
    {
        if (blank(config('reelflow.google.client_id')) || blank(config('reelflow.google.client_secret'))) {
            return [false, 'Google: save the Client ID and Client secret first.'];
        }

        try {
            $response = Http::asForm()->timeout(20)->post(config('reelflow.google.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => 'reelflow-credential-check',
                'client_id' => config('reelflow.google.client_id'),
                'client_secret' => config('reelflow.google.client_secret'),
                'redirect_uri' => config('reelflow.google.redirect_uri'),
            ]);
        } catch (Throwable $e) {
            return [false, 'Google: could not reach the token endpoint ('.SecretRedactor::redactString($e->getMessage()).').'];
        }

        return match ($response->json('error')) {
            'invalid_grant' => [true, 'Google accepted the Client ID and secret. Callback to register: '.config('reelflow.google.redirect_uri').(config('reelflow.youtube_enabled') ? '' : ' (YouTube publishing is still off.)')],
            'invalid_client', 'unauthorized_client' => [false, 'Google rejected the Client ID or secret: '.$this->errorText($response)],
            default => [false, 'Unexpected answer from Google: '.$this->errorText($response)],
        };
    }

    /**
     * Listing the models costs no quota and proves each key is accepted.
     *
     * @return array{0: bool, 1: string}
     */
    private function testAi(): array
    {
        $provider = config('reelflow.ai.provider');
        if ($provider === 'none') {
            return [true, 'AI captions are off (provider "none"); nothing to test.'];
        }

        $keys = config('reelflow.ai.api_keys') ?: [];
        if ($provider === 'anthropic') {
            $url = rtrim(config('reelflow.ai.base_url') ?: 'https://api.anthropic.com', '/').'/v1/models';
            $requiresKey = true;
        } else {
            $preset = config("reelflow.ai.presets.{$provider}");
            if (! $preset) {
                return [false, "AI: unknown provider \"{$provider}\"."];
            }
            $base = config('reelflow.ai.base_url') ?: $preset['base_url'];
            if (blank($base)) {
                return [false, 'AI: this provider needs a Base URL.'];
            }
            $url = rtrim($base, '/').'/models';
            $requiresKey = $preset['requires_key'];
        }

        if ($keys === []) {
            if ($requiresKey) {
                return [false, "AI ({$provider}): save an API key first."];
            }
            $keys = [null];
        }

        $ok = true;
        $lines = [];
        foreach ($keys as $i => $key) {
            $label = $key === null ? 'No key' : 'Key '.($i + 1).' ('.Credentials::mask($key).')';
            try {
                $request = Http::timeout(20)->acceptJson();
                $request = $provider === 'anthropic'
                    ? $request->withHeaders(['x-api-key' => (string) $key, 'anthropic-version' => '2023-06-01'])
                    : ($key === null ? $request : $request->withToken($key));
                $response = $request->get($url);
            } catch (Throwable $e) {
                $ok = false;
                $lines[] = $label.': unreachable ('.SecretRedactor::redactString($e->getMessage()).')';

                continue;
            }

            if ($response->successful()) {
                $lines[] = $label.': OK';
            } elseif ($response->status() === 429) {
                $lines[] = $label.': accepted, but out of quota right now';
            } else {
                $ok = false;
                $lines[] = $label.': rejected ('.$this->errorText($response).')';
            }
        }

        return [$ok, "AI ({$provider}): ".implode('; ', $lines).'.'];
    }

    /**
     * Connect and sign in to the SMTP server without sending anything.
     *
     * @return array{0: bool, 1: string}
     */
    private function testMail(): array
    {
        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $transport->start();
            $transport->stop();
        } catch (Throwable $e) {
            return [false, 'SMTP connection failed: '.SecretRedactor::redactString($e->getMessage())];
        }

        $note = config('mail.default') === 'smtp' ? '' : ' Sending is still set to "'.config('mail.default').'", so emails are not sent until you switch it to SMTP.';

        return [true, 'SMTP connection and sign-in succeeded.'.$note];
    }

    private function errorText(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('error_description')
            ?? (is_string($response->json('error')) ? $response->json('error') : null)
            ?? $response->json('0.error.message');

        return SecretRedactor::redactString(($message ? $message.' ' : '').'(HTTP '.$response->status().')');
    }
}
