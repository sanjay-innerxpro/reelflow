<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    use BelongsToWorkspace;

    /** System-wide entries (no workspace) are allowed. */
    protected static bool $workspaceOptional = true;

    protected $fillable = ['type', 'channel', 'recipient', 'subject', 'status', 'related_type', 'related_id', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
