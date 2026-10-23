<?php

namespace App\Support;

use App\Models\SystemSetting;
use Dotenv\Dotenv;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

/**
 * Platform credentials a super-admin manages in Admin → Credentials: the
 * Meta and Google apps, AI keys, the Drive API key and the mail server.
 *
 * Values are stored encrypted with APP_KEY in system_settings and laid over
 * the .env-based config when the app boots. A value saved in the panel
 * wins; removing it falls back to .env again. Services keep reading
 * config() as before, so nothing else needs to know where a value came from.
 */
class Credentials
{
    private const CACHE_KEY = 'reelflow.credentials';

    /** Row written by the first import from .env (see importFromEnv). */
    public const IMPORT_MARKER = '_imported_from_env';

    /** @var array<string, mixed>|null config values from .env, by config path, before any override */
    private ?array $base = null;

    /** @var array<string, string> decrypted values saved in the panel, by field key */
    private array $stored = [];

    /** @var array<int, string> field keys whose saved value no longer decrypts (APP_KEY changed) */
    private array $unreadable = [];

    private bool $loaded = false;

    /**
     * Field options: label, config (path), env (variable it overrides),
     * env_also (more variables feeding the same value), type (text|secret|keys|url|email|select), options (select), cast
     * (bool|int), rules (extra validation), callback (OAuth redirect path),
     * advanced (folded away), placeholder, help.
     *
     * @return array<string, array{short: string, title: string, help: string, link?: array{0: string, 1: string}, test?: bool, fields: array<string, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            'meta' => [
                'short' => 'Meta',
                'title' => 'Facebook & Instagram (Meta app)',
                'help' => 'App ID and App secret are under App settings → Basic in your Meta app. Add the redirect URI below under Facebook Login → Settings → Valid OAuth Redirect URIs.',
                'link' => ['https://developers.facebook.com/apps/', 'Meta for Developers'],
                'test' => true,
                'fields' => [
                    'meta.app_id' => ['label' => 'App ID', 'config' => 'reelflow.meta.app_id', 'env' => 'META_APP_ID', 'rules' => ['regex:/^\d{5,32}$/'], 'placeholder' => 'e.g. 1234567890123456'],
                    'meta.app_secret' => ['label' => 'App secret', 'config' => 'reelflow.meta.app_secret', 'env' => 'META_APP_SECRET', 'type' => 'secret', 'rules' => ['regex:/^\S{8,255}$/']],
                    'meta.config_id' => ['label' => 'Login for Business configuration ID', 'config' => 'reelflow.meta.config_id', 'env' => 'META_CONFIG_ID', 'rules' => ['regex:/^\d{5,32}$/'], 'help' => 'Only for apps that use "Facebook Login for Business". Leave empty otherwise.'],
                    'meta.redirect_uri' => ['label' => 'Redirect URI', 'config' => 'reelflow.meta.redirect_uri', 'env' => 'META_REDIRECT_URI', 'type' => 'url', 'callback' => 'oauth/meta/callback'],
                    'meta.graph_version' => ['label' => 'Graph API version', 'config' => 'reelflow.meta.graph_version', 'env' => 'META_GRAPH_VERSION', 'rules' => ['regex:/^v\d{1,3}\.\d{1,2}$/'], 'placeholder' => 'e.g. v23.0'],
                ],
            ],
            'google' => [
                'short' => 'Google',
                'title' => 'YouTube (Google OAuth client)',
                'help' => 'Create an OAuth client of type "Web application" under APIs & Services → Credentials, with the YouTube Data API v3 enabled. Add the redirect URI below as an authorised redirect URI.',
                'link' => ['https://console.cloud.google.com/apis/credentials', 'Google Cloud Console'],
                'test' => true,
                'fields' => [
                    'google.client_id' => ['label' => 'Client ID', 'config' => 'reelflow.google.client_id', 'env' => 'GOOGLE_CLIENT_ID', 'rules' => ['regex:/^\S{10,255}$/'], 'placeholder' => '…apps.googleusercontent.com'],
                    'google.client_secret' => ['label' => 'Client secret', 'config' => 'reelflow.google.client_secret', 'env' => 'GOOGLE_CLIENT_SECRET', 'type' => 'secret', 'rules' => ['regex:/^\S{8,255}$/']],
                    'google.redirect_uri' => ['label' => 'Redirect URI', 'config' => 'reelflow.google.redirect_uri', 'env' => 'GOOGLE_REDIRECT_URI', 'type' => 'url', 'callback' => 'oauth/google/callback'],
                    'youtube.enabled' => ['label' => 'YouTube publishing', 'config' => 'reelflow.youtube_enabled', 'env' => 'YOUTUBE_ENABLED', 'type' => 'select', 'cast' => 'bool', 'options' => ['1' => 'On', '0' => 'Off'], 'help' => 'Turn on once the Google project has enough upload quota.'],
                ],
            ],
            'ai' => [
                'short' => 'AI',
                'title' => 'AI captions',
                'help' => 'Free keys: Gemini from Google AI Studio, or Groq from console.groq.com. Ollama runs on your own server and needs no key.',
                'link' => ['https://aistudio.google.com/apikey', 'Google AI Studio'],
                'test' => true,
                'fields' => [
                    'ai.provider' => ['label' => 'Provider', 'config' => 'reelflow.ai.provider', 'env' => 'AI_PROVIDER', 'type' => 'select', 'options' => [
                        'gemini' => 'Gemini (free tier)', 'groq' => 'Groq (free tier)', 'ollama' => 'Ollama (self-hosted, free)',
                        'compatible' => 'Other OpenAI-compatible API', 'openai' => 'OpenAI (paid)', 'anthropic' => 'Anthropic Claude (paid)', 'none' => 'Off: write captions manually',
                    ]],
                    'ai.api_keys' => ['label' => 'API keys', 'config' => 'reelflow.ai.api_keys', 'env' => 'AI_API_KEY', 'env_also' => ['AI_API_KEY_2', 'AI_API_KEY_3', 'AI_API_KEY_4'], 'type' => 'keys', 'rules' => ['max:4000'], 'help' => 'One key per line. The first is used; the others are spares, tried only when a key is out of quota.'],
                    'ai.model' => ['label' => 'Model', 'config' => 'reelflow.ai.model', 'env' => 'AI_MODEL', 'rules' => ['regex:/^\S{1,150}$/'], 'help' => 'Empty uses the provider default (Gemini: gemini-flash-latest).'],
                    'ai.base_url' => ['label' => 'Base URL', 'config' => 'reelflow.ai.base_url', 'env' => 'AI_BASE_URL', 'type' => 'url', 'help' => 'Only for "Other OpenAI-compatible API" or a non-default endpoint.'],
                    'ai.transcription.provider' => ['label' => 'Audio transcription', 'config' => 'reelflow.ai.transcription.provider', 'env' => 'TRANSCRIPTION_PROVIDER', 'type' => 'select', 'advanced' => true, 'options' => [
                        'none' => 'Off', 'groq' => 'Groq Whisper (free tier)', 'compatible' => 'Self-hosted Whisper (OpenAI-compatible)', 'openai' => 'OpenAI Whisper (paid)',
                    ]],
                    'ai.transcription.api_key' => ['label' => 'Transcription API key', 'config' => 'reelflow.ai.transcription.api_key', 'env' => 'TRANSCRIPTION_API_KEY', 'type' => 'secret', 'advanced' => true, 'rules' => ['regex:/^\S{8,500}$/'], 'help' => 'Empty uses the first AI key.'],
                    'ai.transcription.model' => ['label' => 'Transcription model', 'config' => 'reelflow.ai.transcription.model', 'env' => 'TRANSCRIPTION_MODEL', 'advanced' => true, 'rules' => ['regex:/^\S{1,150}$/']],
                    'ai.transcription.base_url' => ['label' => 'Transcription base URL', 'config' => 'reelflow.ai.transcription.base_url', 'env' => 'TRANSCRIPTION_BASE_URL', 'type' => 'url', 'advanced' => true],
                ],
            ],
            'drive' => [
                'short' => 'Drive',
                'title' => 'Google Drive import',
                'help' => "Optional. Without a key, shared folders are read from Drive's public folder page. A free API key with the Google Drive API enabled uses the official API instead.",
                'link' => ['https://console.cloud.google.com/apis/library/drive.googleapis.com', 'Enable the Drive API'],
                'fields' => [
                    'drive.api_key' => ['label' => 'Drive API key', 'config' => 'reelflow.drive.api_key', 'env' => 'GOOGLE_DRIVE_API_KEY', 'type' => 'secret', 'rules' => ['regex:/^\S{10,255}$/']],
                ],
            ],
            'mail' => [
                'short' => 'Email',
                'title' => 'Email (SMTP)',
                'help' => 'Hostinger: smtp.hostinger.com, port 465, SSL, with a mailbox from hPanel → Emails. Gmail: smtp.gmail.com, port 587, STARTTLS, with an App Password.',
                'test' => true,
                'fields' => [
                    'mail.mailer' => ['label' => 'Sending', 'config' => 'mail.default', 'env' => 'MAIL_MAILER', 'type' => 'select', 'options' => ['smtp' => 'Send through SMTP', 'log' => 'Write to the log only (testing)']],
                    'mail.host' => ['label' => 'SMTP host', 'config' => 'mail.mailers.smtp.host', 'env' => 'MAIL_HOST', 'rules' => ['regex:/^[A-Za-z0-9.-]{1,253}$/'], 'placeholder' => 'smtp.hostinger.com'],
                    'mail.port' => ['label' => 'Port', 'config' => 'mail.mailers.smtp.port', 'env' => 'MAIL_PORT', 'cast' => 'int', 'rules' => ['integer', 'between:1,65535'], 'placeholder' => '465'],
                    'mail.scheme' => ['label' => 'Encryption', 'config' => 'mail.mailers.smtp.scheme', 'env' => 'MAIL_SCHEME', 'type' => 'select', 'options' => ['smtps' => 'SSL (usually port 465)', 'smtp' => 'STARTTLS (usually port 587)']],
                    'mail.username' => ['label' => 'Username', 'config' => 'mail.mailers.smtp.username', 'env' => 'MAIL_USERNAME', 'rules' => ['max:255']],
                    'mail.password' => ['label' => 'Password', 'config' => 'mail.mailers.smtp.password', 'env' => 'MAIL_PASSWORD', 'type' => 'secret', 'rules' => ['max:255']],
                    'mail.from_address' => ['label' => 'From address', 'config' => 'mail.from.address', 'env' => 'MAIL_FROM_ADDRESS', 'type' => 'email'],
                    'mail.from_name' => ['label' => 'From name', 'config' => 'mail.from.name', 'env' => 'MAIL_FROM_NAME', 'rules' => ['max:100']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>> every field, by key
     */
    public static function fields(): array
    {
        return array_merge(...array_values(array_map(fn ($group) => $group['fields'], self::groups())));
    }

    /** HTML input name of a field: "meta.app_id" becomes "meta_app_id". */
    public static function inputName(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    public static function isSecret(array $field): bool
    {
        return in_array($field['type'] ?? 'text', ['secret', 'keys'], true);
    }

    /**
     * Input names that must never be flashed back into the session.
     *
     * @return array<int, string>
     */
    public static function secretInputs(): array
    {
        return array_values(array_map(self::inputName(...), array_keys(array_filter(self::fields(), self::isSecret(...)))));
    }

    /** "abcd1234wxyz" becomes "••••wxyz"; short values show no characters. */
    public static function mask(?string $value): string
    {
        $value = (string) $value;

        return mb_strlen($value) >= 12 ? '••••'.mb_substr($value, -4) : '••••';
    }

    /**
     * @return array<int, string>
     */
    public static function splitKeys(?string $value): array
    {
        return array_values(array_unique(preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY)));
    }

    public static function callbackUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$path;
    }

    /**
     * Load the saved values and lay them over the config. Safe before the
     * table exists (first deploy) and while the database is unreachable:
     * the .env values then stay in effect and loading is tried again later.
     */
    public function apply(): void
    {
        $this->base ??= $this->snapshot();

        try {
            $this->load();
        } catch (Throwable) {
            $this->loaded = false;

            return;
        }

        foreach (self::fields() as $key => $field) {
            $path = $field['config'];
            config([$path => array_key_exists($key, $this->stored) ? $this->cast($field, $this->stored[$key]) : $this->base[$path]]);
        }

        // The first AI key is the one in use; the others are spares.
        config(['reelflow.ai.api_key' => array_key_exists('ai.api_keys', $this->stored)
            ? (config('reelflow.ai.api_keys')[0] ?? null)
            : $this->base['reelflow.ai.api_key']]);

        // OAuth redirect URIs default to this app's own callback routes.
        foreach (self::fields() as $field) {
            if (isset($field['callback']) && blank(config($field['config']))) {
                config([$field['config'] => self::callbackUrl($field['callback'])]);
            }
        }
    }

    /** Whether the saved values are in effect (false while the database was unreachable). */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Save values from the panel and apply them at once.
     *
     * @param  array<string, string|null>  $values  field key => new value; null or '' removes the saved value
     * @return array<int, string> the keys that changed
     */
    public function save(array $values, ?int $userId = null): array
    {
        if (! $this->loaded) {
            $this->base ??= $this->snapshot();
            $this->load();
        }

        $fields = self::fields();
        $changed = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                throw new InvalidArgumentException("Unknown credential [{$key}].");
            }

            $value = self::normalize($fields[$key], $value);
            if ($value === ($this->stored[$key] ?? null) && ! $this->isUnreadable($key)) {
                continue;
            }

            if ($value === null) {
                SystemSetting::where('key', $key)->delete();
            } else {
                SystemSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
            }
            $changed[] = $key;
        }

        Cache::forget(self::CACHE_KEY);
        $this->apply();

        return $changed;
    }

    /** The value saved in the panel, if any. */
    public function stored(string $key): ?string
    {
        return $this->stored[$key] ?? null;
    }

    /** The value from .env (or the config default), ignoring the panel. */
    public function envValue(string $key): mixed
    {
        $this->base ??= $this->snapshot();

        return $this->base[self::fields()[$key]['config']] ?? null;
    }

    /** A value was saved but can no longer be decrypted, so .env is in use. */
    public function isUnreadable(string $key): bool
    {
        return in_array($key, $this->unreadable, true);
    }

    /**
     * Copy the credentials the server's .env sets into the panel, for every
     * field that has no saved value yet. Config defaults that .env does not
     * set (an unset MAIL_HOST, say) are not copied, so they stay defaults.
     *
     * @return array<int, string> the keys that were imported
     */
    public function importFromEnv(?int $userId = null): array
    {
        if (! $this->loaded) {
            $this->base ??= $this->snapshot();
            $this->load();
        }

        $present = $this->envNamesWithValues();
        $values = [];
        foreach (self::fields() as $key => $field) {
            if ($this->stored($key) !== null && ! $this->isUnreadable($key)) {
                continue;
            }
            if (array_intersect([$field['env'], ...($field['env_also'] ?? [])], $present) === []) {
                continue;
            }

            $value = $this->envValue($key);
            $value = match (true) {
                is_array($value) => implode("\n", $value),
                is_bool($value) => $value ? '1' : '0',
                default => trim((string) $value),
            };
            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        $imported = $values === [] ? [] : $this->save($values, $userId);
        SystemSetting::updateOrCreate(['key' => self::IMPORT_MARKER], ['value' => now()->toIso8601String(), 'updated_by' => $userId]);

        return $imported;
    }

    public function wasImportedFromEnv(): bool
    {
        return SystemSetting::where('key', self::IMPORT_MARKER)->exists();
    }

    /**
     * Variables that have a value in the .env file or the real environment.
     *
     * @return array<int, string>
     */
    private function envNamesWithValues(): array
    {
        $isSet = fn ($value) => is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'null';

        try {
            $path = app()->environmentFilePath();
            $file = is_readable($path) ? Dotenv::parse((string) file_get_contents($path)) : [];
        } catch (Throwable) {
            $file = [];
        }
        $names = array_keys(array_filter($file, $isSet));

        foreach (self::fields() as $field) {
            foreach ([$field['env'], ...($field['env_also'] ?? [])] as $name) {
                if ($isSet(getenv($name)) || $isSet($_ENV[$name] ?? null) || $isSet($_SERVER[$name] ?? null)) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function load(): void
    {
        // The cache holds ciphertext only; values are decrypted per process.
        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::query()->toBase()->pluck('value', 'key')->all());

        $fields = self::fields();
        $this->stored = [];
        $this->unreadable = [];
        foreach ($rows as $key => $ciphertext) {
            if (! isset($fields[$key])) {
                continue;
            }
            try {
                $this->stored[$key] = Crypt::decryptString($ciphertext);
            } catch (DecryptException) {
                $this->unreadable[] = $key;
            }
        }
        $this->loaded = true;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $paths = array_column(self::fields(), 'config');
        $paths[] = 'reelflow.ai.api_key';

        return array_combine($paths, array_map(fn ($path) => config($path), $paths));
    }

    private function cast(array $field, string $value): mixed
    {
        if (($field['type'] ?? null) === 'keys') {
            return self::splitKeys($value);
        }

        return match ($field['cast'] ?? null) {
            'bool' => $value === '1',
            'int' => (int) $value,
            default => $value,
        };
    }

    private static function normalize(array $field, ?string $value): ?string
    {
        $value = trim((string) $value);
        if (($field['type'] ?? null) === 'keys') {
            $value = implode("\n", self::splitKeys($value));
        }

        return $value === '' ? null : $value;
    }
}
