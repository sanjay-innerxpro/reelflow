<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadChunk extends Model
{
    protected $fillable = ['upload_session_id', 'chunk_index', 'size', 'sha256'];

    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }
}
