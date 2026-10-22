<?php

namespace App\Services\Ai;

/**
 * A vision-capable model that returns JSON for the caption prompt. Business
 * logic depends only on this interface; providers are chosen by AI_PROVIDER.
 */
interface AiVisionProvider
{
    public function name(): string;

    public function model(): string;

    /**
     * @throws AiProviderException
     */
    public function generate(AiRequest $request): AiResponse;
}
