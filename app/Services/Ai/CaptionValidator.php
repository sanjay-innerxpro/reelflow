<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Validator;

/**
 * Validates model output against the caption schema and normalises it to
 * platform limits. Structural problems are rejected; over-long values are
 * trimmed and the adjustment is recorded.
 */
class CaptionValidator
{
    public function __construct(private readonly CaptionShapeRepair $repair = new CaptionShapeRepair) {}

    public function parse(string $raw): CaptionResult
    {
        [$data, $repairs] = $this->repair->repair($this->decode($raw));

        $validator = Validator::make($data, [
            'topic' => ['present', 'nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'language' => ['required', 'string', 'max:20'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'facebook_caption' => ['present', 'nullable', 'string', 'max:10000'],
            'facebook_hashtags' => ['present', 'array', 'max:50'],
            'facebook_hashtags.*' => ['string', 'max:100'],
            'instagram_caption' => ['present', 'nullable', 'string', 'max:10000'],
            'instagram_hashtags' => ['present', 'array', 'max:50'],
            'instagram_hashtags.*' => ['string', 'max:100'],
            'youtube_title' => ['present', 'nullable', 'string', 'max:300'],
            'youtube_description' => ['present', 'nullable', 'string', 'max:10000'],
            'youtube_hashtags' => ['sometimes', 'array', 'max:50'],
            'youtube_hashtags.*' => ['string', 'max:100'],
            'youtube_tags' => ['present', 'array', 'max:100'],
            'youtube_tags.*' => ['string', 'max:100'],
            'content_warnings' => ['present', 'array', 'max:20'],
            'content_warnings.*' => ['string', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw new InvalidAiResponse('AI response failed schema validation.', $validator->errors()->toArray());
        }

        $confidence = (float) $data['confidence'];
        $lowConfidence = $confidence < config('reelflow.ai.low_confidence_threshold');

        if (! $lowConfidence) {
            foreach (['facebook_caption', 'instagram_caption', 'youtube_title'] as $field) {
                if (blank($data[$field] ?? null)) {
                    throw new InvalidAiResponse("AI response is missing {$field}.", [$field => ['required']]);
                }
            }
        }

        $adjustments = $repairs;

        $igMax = config('reelflow.meta.instagram_max_hashtags');
        $igTags = $this->hashtags($data['instagram_hashtags']);
        if (count($igTags) > $igMax) {
            $igTags = array_slice($igTags, 0, $igMax);
            $adjustments[] = "Instagram hashtags trimmed to {$igMax}.";
        }

        $igCaption = trim((string) ($data['instagram_caption'] ?? ''));
        $igTagText = mb_strlen(implode(' ', array_map(fn ($t) => '#'.$t, $igTags))) + 2;
        $igLimit = config('reelflow.meta.instagram_max_caption') - $igTagText;
        if (mb_strlen($igCaption) > $igLimit) {
            $igCaption = rtrim(mb_substr($igCaption, 0, max(0, $igLimit - 1))).'…';
            $adjustments[] = 'Instagram caption shortened to fit 2,200 characters.';
        }

        $title = trim(str_replace(['<', '>'], '', (string) ($data['youtube_title'] ?? '')));
        if (mb_strlen($title) > 100) {
            $title = rtrim(mb_substr($title, 0, 99)).'…';
            $adjustments[] = 'YouTube title shortened to 100 characters.';
        }

        $description = str_replace(['<', '>'], '', (string) ($data['youtube_description'] ?? ''));
        if (strlen($description) > 4800) {
            $description = mb_strcut($description, 0, 4800);
            $adjustments[] = 'YouTube description shortened.';
        }

        [$tags, $trimmed] = $this->youtubeTags($data['youtube_tags']);
        if ($trimmed) {
            $adjustments[] = 'YouTube tags trimmed to 500 characters.';
        }

        return new CaptionResult(
            topic: trim((string) ($data['topic'] ?? '')),
            category: filled($data['category'] ?? null) ? trim($data['category']) : null,
            language: strtolower(trim($data['language'])),
            confidence: round($confidence, 3),
            facebookCaption: trim((string) ($data['facebook_caption'] ?? '')),
            facebookHashtags: array_slice($this->hashtags($data['facebook_hashtags']), 0, 30),
            instagramCaption: $igCaption,
            instagramHashtags: $igTags,
            youtubeTitle: $title,
            youtubeDescription: trim($description),
            youtubeHashtags: array_slice($this->hashtags($data['youtube_hashtags'] ?? []), 0, 15),
            youtubeTags: $tags,
            contentWarnings: array_values(array_filter(array_map('trim', $data['content_warnings']))),
            adjustments: $adjustments,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') {
            throw new InvalidAiResponse('The AI returned an empty response.');
        }

        // Tolerate a fenced block even though the prompt forbids it.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        $data = $this->decodeJson($text);

        // Some endpoints return the JSON document as a JSON string.
        if (is_string($data)) {
            $data = $this->decodeJson(trim($data));
        }

        if (! is_array($data) || array_is_list($data)) {
            // A single-element list is a wrapped object often enough to unwrap.
            if (is_array($data) && count($data) === 1 && is_array($data[0]) && ! array_is_list($data[0])) {
                return $data[0];
            }

            throw new InvalidAiResponse('AI response must be a JSON object.');
        }

        return $data;
    }

    /**
     * Decode, falling back to the outermost {...} or [...] when the model
     * wrapped the document in prose.
     */
    private function decodeJson(string $text): mixed
    {
        $data = json_decode($text, true, 64);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($text, $start, $end - $start + 1), true, 64);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
            }
        }

        throw new InvalidAiResponse('AI response is not valid JSON: '.json_last_error_msg());
    }

    /**
     * Normalise to bare tags (no "#"), drop invalid ones, de-duplicate.
     *
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    public function hashtags(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $tag = preg_replace('/\s+/u', '', ltrim(trim((string) $tag), '#')) ?? '';
            if ($tag === '' || ! preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $tag) || mb_strlen($tag) > 60) {
                continue;
            }
            $out[mb_strtolower($tag)] ??= $tag;
        }

        return array_values($out);
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return array{0: array<int, string>, 1: bool}
     */
    private function youtubeTags(array $tags): array
    {
        $out = [];
        $total = 0;
        $trimmed = false;
        foreach ($tags as $tag) {
            $tag = trim(str_replace(['<', '>', ','], '', (string) $tag));
            if ($tag === '' || in_array(mb_strtolower($tag), array_map('mb_strtolower', $out), true)) {
                continue;
            }
            // YouTube counts quotes around multi-word tags and separating commas.
            $cost = mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0) + 1;
            if ($total + $cost > 500) {
                $trimmed = true;
                break;
            }
            $total += $cost;
            $out[] = $tag;
        }

        return [$out, $trimmed];
    }
}
