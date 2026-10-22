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
            $this->validator->parse(FakeAiProvider::validJson(['confidence' => 7, 'facebook_hashtags' => 'not-an-array']));
            $this->fail('Expected validation failure');
        } catch (InvalidAiResponse $e) {
            $this->assertArrayHasKey('confidence', $e->errors);
            $this->assertArrayHasKey('facebook_hashtags', $e->errors);
        }
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
