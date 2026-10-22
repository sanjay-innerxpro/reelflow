<?php

namespace App\Models;

use App\Enums\UploadSessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_id', 'sync_device_id', 'total_size', 'chunk_size', 'total_chunks', 'status', 'error', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => UploadSessionStatus::class,
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function chunkDirectory(): string
    {
        return 'upload-chunks/'.$this->id;
    }

    public function chunkPath(int $index): string
    {
        return $this->chunkDirectory().'/'.str_pad((string) $index, 6, '0', STR_PAD_LEFT).'.part';
    }

    public function expectedChunkSize(int $index): int
    {
        if ($index === $this->total_chunks - 1) {
            return $this->total_size - ($this->chunk_size * ($this->total_chunks - 1));
        }

        return $this->chunk_size;
    }

    public function isOpen(): bool
    {
        return $this->status === UploadSessionStatus::Open && $this->expires_at->isFuture();
    }

    /**
     * @return array<int, int>
     */
    public function receivedChunkIndexes(): array
    {
        return $this->chunks()->orderBy('chunk_index')->pluck('chunk_index')->map(fn ($i) => (int) $i)->all();
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function syncDevice(): BelongsTo
    {
        return $this->belongsTo(SyncDevice::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(UploadChunk::class);
    }
}
