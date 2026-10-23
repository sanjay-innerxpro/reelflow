<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadBatch extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPLOADING = 'uploading';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const KIND_ZIP = 'zip';

    public const KIND_VIDEO = 'video';

    protected $fillable = [
        'user_id', 'original_name', 'kind', 'size_bytes', 'chunk_size', 'total_chunks', 'status', 'results',
        'added_count', 'error', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'results' => 'array',
            'added_count' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::UPLOADING && $this->expires_at->isFuture();
    }

    public function chunkDirectory(): string
    {
        return 'web-uploads/'.$this->id;
    }

    public function chunkPath(int $index): string
    {
        return $this->chunkDirectory().'/'.$index;
    }

    public function expectedChunkSize(int $index): int
    {
        return $index === $this->total_chunks - 1
            ? $this->size_bytes - $this->chunk_size * ($this->total_chunks - 1)
            : $this->chunk_size;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
