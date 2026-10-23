<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use BelongsToWorkspace;

    /** System-wide entries (no workspace) are allowed. */
    protected static bool $workspaceOptional = true;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'from_status', 'to_status', 'level',
        'message', 'properties', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
