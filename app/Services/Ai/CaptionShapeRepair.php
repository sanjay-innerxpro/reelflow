<?php

namespace App\Services\Ai;

/**
 * Models reach the right content in the wrong shape far more often than they
 * get the content wrong: a wrapper object, per-platform sub-objects, camelCase
 * keys, a hashtag string instead of a list, "90%" instead of 0.9. Rejecting
 * those costs a whole generation, so the payload is repaired into the flat
 * schema first and every repair is reported back for the activity log.
 */
class CaptionShapeRepair
{
    private const STRING_FIELDS = [
        'topic', 'category', 'language', 'facebook_caption', 'instagram_caption',
        'youtube_title', 'youtube_description',
    ];

    private const ARRAY_FIELDS = [
        'facebook_hashtags', 'instagram_hashtags', 'youtube_hashtags', 'youtube_tags', 'content_warnings',
    ];

    /** Keys models use for our fields, mapped to the schema name. */
    private const ALIASES = [
        'video_topic' => 'topic', 'subject' => 'topic',
        'niche' => 'category', 'genre' => 'category', 'content_category' => 'category',
        'lang' => 'language', 'language_code' => 'language', 'detected_language' => 'language',
        'confidence_score' => 'confidence', 'confidence_level' => 'confidence', 'certainty' => 'confidence',
        'fb_caption' => 'facebook_caption', 'fb_hashtags' => 'facebook_hashtags',
        'ig_caption' => 'instagram_caption', 'ig_hashtags' => 'instagram_hashtags',
        'yt_title' => 'youtube_title', 'yt_description' => 'youtube_description',
        'yt_hashtags' => 'youtube_hashtags', 'yt_tags' => 'youtube_tags',
        'title' => 'youtube_title', 'description' => 'youtube_description',
        'youtube_keywords' => 'youtube_tags', 'keywords' => 'youtube_tags', 'tags' => 'youtube_tags',
        'warnings' => 'content_warnings', 'content_warning' => 'content_warnings', 'safety_warnings' => 'content_warnings',
    ];

    /** Sub-object key => [their key => our key]. */
    private const PLATFORM_KEYS = [
        'facebook' => ['caption' => 'facebook_caption', 'hashtags' => 'facebook_hashtags'],
        'instagram' => ['caption' => 'instagram_caption', 'hashtags' => 'instagram_hashtags'],
        'youtube' => [
            'title' => 'youtube_title', 'description' => 'youtube_description', 'caption' => 'youtube_description',
            'hashtags' => 'youtube_hashtags', 'tags' => 'youtube_tags', 'keywords' => 'youtube_tags',
        ],
    ];

    /** @var array<int, string> */
    private array $repairs = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<int, string>} [payload, repairs]
     */
    public function repair(array $data): array
    {
        $this->repairs = [];

        $data = $this->unwrap($data);
        $data = $this->canonicaliseKeys($data);
        $data = $this->flattenPlatforms($data);
        $data = $this->shareLooseFields($data);
        $data = $this->coerce($data);
        $data = $this->fillPresentFields($data);

        return [$data, $this->repairs];
    }

    /**
     * Step into a wrapper such as {"result": {...}} or {"captions": {...}}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function unwrap(array $data): array
    {
        for ($depth = 0; $depth < 3 && $this->score($data) < 3; $depth++) {
            $best = null;
            foreach ($data as $value) {
                if (is_array($value) && ! array_is_list($value) && $this->score($value) > $this->score($best ?? [])) {
                    $best = $value;
                }
            }
            if ($best === null || $this->score($best) <= $this->score($data)) {
                break;
            }
            // Keep any usable siblings; the inner object wins on conflict.
            $data = array_merge(array_filter($data, 'is_scalar'), $best);
            $this->repairs[] = 'Captions were read out of a wrapper object.';
        }

        return $data;
    }

    /**
     * How many schema fields an object carries — used to find the real payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function score(array $data): int
    {
        $known = array_merge(self::STRING_FIELDS, self::ARRAY_FIELDS, ['confidence'], array_keys(self::PLATFORM_KEYS));

        return count(array_intersect(array_map([$this, 'normaliseKey'], array_keys($data)), $known));
    }

    private function normaliseKey(string $key): string
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', trim($key)) ?? $key);
        $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicaliseKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $canonical = $this->normaliseKey((string) $key);
            if ($canonical !== (string) $key && ! array_key_exists($canonical, $data)) {
                $this->repairs[] = "Field \"{$key}\" was read as {$canonical}.";
            }
            // A real key already present always wins over an alias.
            if (! array_key_exists($canonical, $out) || array_key_exists($canonical, $data)) {
                $out[$canonical] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flattenPlatforms(array $data): array
    {
        foreach (self::PLATFORM_KEYS as $platform => $map) {
            $nested = $data[$platform] ?? null;
            if (! is_array($nested) || array_is_list($nested)) {
                continue;
            }
            unset($data[$platform]);
            foreach ($nested as $key => $value) {
                $target = $map[$this->normaliseKey((string) $key)] ?? $map[strtolower((string) $key)] ?? null;
                if ($target !== null && ! isset($data[$target])) {
                    $data[$target] = $value;
                }
            }
            $this->repairs[] = ucfirst($platform).' content was read from a nested object.';
        }

        return $data;
    }

    /**
     * A single "caption"/"hashtags" at the top level is meant for every platform.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function shareLooseFields(array $data): array
    {
        foreach (['caption' => ['facebook_caption', 'instagram_caption'], 'hashtags' => ['facebook_hashtags', 'instagram_hashtags', 'youtube_hashtags']] as $loose => $targets) {
            if (! isset($data[$loose])) {
                continue;
            }
            $used = false;
            foreach ($targets as $target) {
                if (! isset($data[$target])) {
                    $data[$target] = $data[$loose];
                    $used = true;
                }
            }
            unset($data[$loose]);
            if ($used) {
                $this->repairs[] = "One \"{$loose}\" value was used for every platform that had none.";
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function coerce(array $data): array
    {
        foreach (self::STRING_FIELDS as $field) {
            if (array_key_exists($field, $data) && ! is_string($data[$field]) && $data[$field] !== null) {
                $data[$field] = $this->toString($data[$field], $field);
            }
        }

        foreach (self::ARRAY_FIELDS as $field) {
            if (array_key_exists($field, $data) && ! is_array($data[$field])) {
                $data[$field] = $this->toList($data[$field], $field);
            } elseif (isset($data[$field]) && is_array($data[$field]) && ! array_is_list($data[$field])) {
                $data[$field] = array_values($data[$field]);
            }
        }

        if (array_key_exists('confidence', $data)) {
            $data['confidence'] = $this->toConfidence($data['confidence']);
        }

        if (isset($data['language']) && is_string($data['language'])) {
            $data['language'] = $this->toLanguage($data['language']);
        }

        return $data;
    }

    private function toString(mixed $value, string $field): ?string
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            // ["line", "line"] is a caption split up; {"text": "..."} is a wrapper.
            foreach (['text', 'value', 'caption', 'content'] as $key) {
                if (isset($value[$key]) && is_string($value[$key])) {
                    $this->repairs[] = "Field {$field} was unwrapped from an object.";

                    return $value[$key];
                }
            }
            $parts = array_filter($value, 'is_string');
            if ($parts !== []) {
                $this->repairs[] = "Field {$field} was joined from a list.";

                return implode($field === 'youtube_description' || str_ends_with($field, '_caption') ? "\n" : ' ', $parts);
            }
        }

        $this->repairs[] = "Field {$field} was not usable text and was dropped.";

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function toList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $parts = preg_split('/[,\n]+|\s+(?=#)/u', $value) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
            if ($parts !== []) {
                $this->repairs[] = "Field {$field} was split out of a single string.";

                return $parts;
            }

            return [];
        }

        $this->repairs[] = "Field {$field} was not a list and was dropped.";

        return [];
    }

    private function toConfidence(mixed $value): mixed
    {
        if (is_string($value)) {
            $text = strtolower(trim($value));
            $worded = ['very high' => 0.95, 'high' => 0.85, 'good' => 0.8, 'medium' => 0.65, 'moderate' => 0.65, 'low' => 0.35, 'very low' => 0.2];
            if (isset($worded[rtrim($text, '.')])) {
                $this->repairs[] = 'Confidence "'.$value.'" was read as a number.';

                return $worded[rtrim($text, '.')];
            }
            if (! is_numeric(rtrim($text, '%'))) {
                return $value; // Let validation report it.
            }
            $value = (float) rtrim($text, '%') / (str_ends_with($text, '%') ? 100 : 1);
            $this->repairs[] = 'Confidence was read from text.';
        }

        if (! is_int($value) && ! is_float($value)) {
            return $value;
        }

        // "8" means 8/10 and "85" means 85%: either way, not 8.0 or 85.0.
        if ($value > 1 && $value <= 10) {
            $this->repairs[] = 'Confidence was rescaled from a 0-10 score.';

            return round($value / 10, 3);
        }
        if ($value > 10 && $value <= 100) {
            $this->repairs[] = 'Confidence was rescaled from a percentage.';

            return round($value / 100, 3);
        }

        return $value;
    }

    private function toLanguage(string $value): string
    {
        $text = strtolower(trim($value));
        $map = [
            'english' => 'en', 'en-us' => 'en', 'en_us' => 'en', 'en-gb' => 'en',
            'hindi' => 'hi', 'hindi (devanagari)' => 'hi', 'devanagari' => 'hi', 'hi-in' => 'hi',
            'hinglish (hindi in latin script)' => 'hinglish', 'hindi-english' => 'hinglish', 'mixed' => 'hinglish',
        ];
        if (isset($map[$text])) {
            $this->repairs[] = "Language \"{$value}\" was read as {$map[$text]}.";

            return $map[$text];
        }

        return $text;
    }

    /**
     * The schema asks for every key to be present. Lists that are simply
     * absent mean "none"; absent text stays absent so validation can judge it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillPresentFields(array $data): array
    {
        $missing = [];
        foreach (self::ARRAY_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                $data[$field] = [];
                $missing[] = $field;
            }
        }
        foreach (['topic', 'category', 'facebook_caption', 'instagram_caption', 'youtube_title', 'youtube_description'] as $field) {
            if (! array_key_exists($field, $data)) {
                $data[$field] = null;
                $missing[] = $field;
            }
        }

        // Judgement fields: a missing confidence must not read as a confident
        // result, so it lands under the review threshold. A payload with no
        // caption at all is not a result worth salvaging, so it keeps no
        // confidence and fails validation.
        $hasContent = filled($data['facebook_caption'] ?? null) || filled($data['instagram_caption'] ?? null)
            || filled($data['youtube_title'] ?? null) || filled($data['youtube_description'] ?? null);
        if (! isset($data['confidence']) && $hasContent) {
            $data['confidence'] = 0.5;
            $this->repairs[] = 'No confidence was reported; this result is flagged for review.';
        }
        if (blank($data['language'] ?? null)) {
            $data['language'] = 'en';
            $this->repairs[] = 'No language was reported; assumed English.';
        }

        if ($missing !== []) {
            $this->repairs[] = 'Missing fields left empty: '.implode(', ', $missing).'.';
        }

        return $data;
    }
}
