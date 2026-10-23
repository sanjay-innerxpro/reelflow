<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSource extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['video_id', 'sync_device_id', 'folder_label', 'relative_path', 'path_hash', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function syncDevice(): BelongsTo
    {
        return $this->belongsTo(SyncDevice::class);
    }
}
