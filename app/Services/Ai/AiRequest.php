<?php

namespace App\Services\Ai;

final class AiRequest
{
    /**
     * @param  array<int, string>  $imagePaths  local JPEG frames
     * @param  array<string, mixed>  $jsonSchema  schema the response must follow
     */
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly array $imagePaths,
        public readonly array $jsonSchema,
        public readonly int $maxOutputTokens,
    ) {}
}
