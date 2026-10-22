<?php

namespace App\Services\Ai;

interface TranscriptionProvider
{
    public function isEnabled(): bool;

    /**
     * @throws AiProviderException
     */
    public function transcribe(string $audioPath): ?string;
}
