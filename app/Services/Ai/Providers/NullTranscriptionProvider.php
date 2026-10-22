<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\TranscriptionProvider;

class NullTranscriptionProvider implements TranscriptionProvider
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function transcribe(string $audioPath): ?string
    {
        return null;
    }
}
