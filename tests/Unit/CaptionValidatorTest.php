<?php

namespace Tests\Unit;

use App\Services\Ai\CaptionPrompt;
use App\Services\Ai\CaptionValidator;
use App\Services\Ai\InvalidAiResponse;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class CaptionValidatorTest extends TestCase
{
    private CaptionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CaptionValidator;
    }

    public function test_valid_response_is_normalised(): void
    {
        $result = $this->validator->parse(FakeAiProvider::validJson());

        $this->assertSame(0.92, $result->confidence);
        $this->assertSame(['masalapasta', 'quickrecipes', 'IndianFood'], $result->facebookHashtags);
        $this->assertCount(5, $result->instagramHashtags, 'Instagram hashtags are capped at the configured maximum');
        $this->assertSame('Masala Pasta in 10 Minutes Easy', $result->youtubeTitle, 'Angle brackets are not allowed by YouTube');
        $this->assertContains('Instagram hashtags trimmed to 5.', $result->adjustments);
        $this->assertFalse($result->isLowConfidence());
    }

    public function test_markdown_fences_are_tolerated(): void
    {
        $result = $this->validator->parse("```json\n".FakeAiProvider::validJson()."\n```");
        $this->assertSame('en', $result->language);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(InvalidAiResponse::class);
        $this->validator->parse('Sure! Here are your captions: {"topic": ');
    }

    public function test_schema_violations_are_rejected(): void
    {
        try {
            $this->validator->parse(FakeAiProvider::validJson(['confidence' => 'banana']));
            $this->fail('Expected validation failure');
        } catch (InvalidAiResponse $e) {
            $this->assertArrayHasKey('confidence', $e->errors);
        }
    }

    public function test_a_payload_without_any_caption_is_rejected(): void
    {
        $this->expectException(InvalidAiResponse::class);
        $this->validator->parse('{"topic": 1}');
    }

    public function test_confidence_on_another_scale_is_rescaled(): void
    {
        $this->assertSame(0.85, $this->validator->parse(FakeAiProvider::validJson(['confidence' => 85]))->confidence);
        $this->assertSame(0.7, $this->validator->parse(FakeAiProvider::validJson(['confidence' => 7]))->confidence);
        $this->assertSame(0.9, $this->validator->parse(FakeAiProvider::validJson(['confidence' => '90%']))->confidence);
        $this->assertSame(0.85, $this->validator->parse(FakeAiProvider::validJson(['confidence' => 'high']))->confidence);
    }

    public function test_hashtags_given_as_one_string_are_split(): void
    {
        $result = $this->validator->parse(FakeAiProvider::validJson(['facebook_hashtags' => '#pasta, #quickrecipe #food']));

        $this->assertSame(['pasta', 'quickrecipe', 'food'], $result->facebookHashtags);
        $this->assertNotEmpty($result->adjustments);
    }

    public function test_captions_nested_per_platform_are_flattened(): void
    {
        $result = $this->validator->parse(json_encode([
            'result' => [
                'topic' => 'Masala pasta', 'category' => 'Food', 'language' => 'English', 'confidence' => 0.9,
                'facebook' => ['caption' => 'FB caption', 'hashtags' => ['pasta']],
                'instagram' => ['caption' => 'IG caption', 'hashtags' => ['pasta']],
                'youtube' => ['title' => 'YT title', 'description' => 'YT description', 'tags' => ['pasta']],
            ],
        ]));

        $this->assertSame('FB caption', $result->facebookCaption);
        $this->assertSame('IG caption', $result->instagramCaption);
        $this->assertSame('YT title', $result->youtubeTitle);
        $this->assertSame(['pasta'], $result->youtubeTags);
        $this->assertSame('en', $result->language, 'A language name is normalised to its code');
        $this->assertSame([], $result->contentWarnings, 'A missing list reads as empty');
    }

    public function test_json_wrapped_in_prose_is_recovered(): void
    {
        $result = $this->validator->parse('Sure! Here are your captions:'."\n".FakeAiProvider::validJson()."\nLet me know if you want changes.");

        $this->assertSame('Masala pasta recipe', $result->topic);
    }

    public function test_confident_response_requires_captions(): void
    {
        $this->expectException(InvalidAiResponse::class);
        $this->validator->parse(FakeAiProvider::validJson(['facebook_caption' => '']));
    }

    public function test_low_confidence_may_omit_captions(): void
    {
        $result = $this->validator->parse(FakeAiProvider::validJson([
            'confidence' => 0.3, 'facebook_caption' => '', 'instagram_caption' => '', 'youtube_title' => '',
        ]));

        $this->assertTrue($result->isLowConfidence());
    }

    public function test_hashtags_are_cleaned_and_deduplicated(): void
    {
        $this->assertSame(['रसोई', 'Cooking'], $this->validator->hashtags(['#रसोई', 'Cooking', 'cooking', 'bad tag!', '', '#']));
    }

    public function test_youtube_tags_are_limited_to_500_characters(): void
    {
        $tags = array_map(fn ($i) => 'tag number '.$i.str_repeat('x', 20), range(1, 40));
        $result = $this->validator->parse(FakeAiProvider::validJson(['youtube_tags' => $tags]));

        $total = array_sum(array_map(fn ($t) => mb_strlen($t) + 3, $result->youtubeTags));
        $this->assertLessThanOrEqual(500, $total);
        $this->assertContains('YouTube tags trimmed to 500 characters.', $result->adjustments);
    }

    public function test_schema_is_strict(): void
    {
        $schema = CaptionPrompt::schema();
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing(array_keys($schema['properties']), $schema['required']);
        foreach (['topic', 'language', 'confidence', 'facebook_caption', 'facebook_hashtags', 'instagram_caption', 'instagram_hashtags', 'youtube_title', 'youtube_description', 'youtube_tags', 'content_warnings'] as $field) {
            $this->assertArrayHasKey($field, $schema['properties']);
        }
    }
}
