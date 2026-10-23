<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriveFile extends Model
{
    public const PENDING = 'pending';

    public const IMPORTED = 'imported';

    // Same content as a video already in the library (e.g. uploaded by the agent).
    public const DUPLICATE = 'duplicate';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = ['drive_folder_id', 'file_id', 'name', 'size_bytes', 'status', 'video_id', 'attempts', 'error', 'imported_at'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'drive_folder_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
