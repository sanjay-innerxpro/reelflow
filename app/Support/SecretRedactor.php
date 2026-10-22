<?php

namespace App\Support;

/**
 * Removes credentials from anything that is about to be logged, stored as
 * API response metadata, or emailed.
 */
class SecretRedactor
{
    private const SENSITIVE_KEY = '/^(code|key)$|token|secret|password|passwd|authorization|api[_-]?key|signature|session_url|upload_url|cookie|credential/i';

    public const MASK = '[REDACTED]';

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function redact($value, int $depth = 0)
    {
        if ($depth > 8) {
            return '[TRUNCATED]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) && ! is_array($item)) {
                    $out[$key] = self::MASK;

                    continue;
                }
                $out[$key] = self::redact($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    public static function redactString(string $value): string
    {
        $patterns = [
            '/(access_token|refresh_token|client_secret|upload_id|key|code|sig|signature|X-Amz-Signature|X-Amz-Credential)=([^&\s"\']+)/i' => '$1='.self::MASK,
            '/(Bearer|OAuth)\s+[A-Za-z0-9\-\._~\+\/]+=*/i' => '$1 '.self::MASK,
            '/\bEAA[A-Za-z0-9]{20,}\b/' => self::MASK,          // Meta access tokens
            '/\bya29\.[A-Za-z0-9\-_\.]+/' => self::MASK,         // Google access tokens
            '/\b1\/\/[A-Za-z0-9\-_]{20,}/' => self::MASK,        // Google refresh tokens
            '/\brfd_[A-Za-z0-9]{20,}\b/' => self::MASK,          // sync device tokens
            '/\bsk-[A-Za-z0-9\-_]{16,}\b/' => self::MASK,        // common AI API keys
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }

    public static function truncate(string $value, int $length = 1000): string
    {
        $value = self::redactString($value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length).'…' : $value;
    }
}
