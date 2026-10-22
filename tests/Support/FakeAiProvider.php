<?php

namespace Tests\Support;

use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\AiVisionProvider;

class FakeAiProvider implements AiVisionProvider
{
    /** @var array<int, string|\Throwable> */
    public array $responses = [];

    /** @var array<int, AiRequest> */
    public array $requests = [];

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-vision-1';
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses) ?? self::validJson();
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return new AiResponse($next, 'fake-vision-1', 1200, 300, 'end_turn');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function validJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'topic' => 'Masala pasta recipe',
            'category' => 'Food',
            'language' => 'en',
            'confidence' => 0.92,
            'facebook_caption' => 'Ten-minute masala pasta using pantry spices.',
            'facebook_hashtags' => ['#masalapasta', 'quickrecipes', 'IndianFood'],
            'instagram_caption' => 'Masala pasta in ten minutes — save this for busy nights.',
            'instagram_hashtags' => ['masalapasta', 'pastarecipe', 'indianfood', 'quickdinner', 'homecooking', 'extra1', 'extra2'],
            'youtube_title' => 'Masala Pasta in 10 Minutes <Easy>',
            'youtube_description' => 'A quick masala pasta made with pantry spices.',
            'youtube_hashtags' => ['shorts', 'masalapasta'],
            'youtube_tags' => ['masala pasta', 'quick recipe', 'indian pasta'],
            'content_warnings' => [],
        ], $overrides));
    }
}
