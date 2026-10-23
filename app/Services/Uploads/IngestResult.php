<?php

namespace App\Services\Uploads;

use App\Models\Video;

final class IngestResult
{
    public const ADDED = 'added';

    public const DUPLICATE = 'duplicate';

    public const SKIPPED = 'skipped';

    public const QUOTA = 'quota';

    public function __construct(
        public readonly string $status,
        public readonly ?Video $video = null,
        public readonly ?string $message = null,
    ) {}
}
