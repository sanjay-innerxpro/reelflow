<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGeneration extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_LOW_CONFIDENCE = 'low_confidence';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'video_id', 'status', 'provider', 'model', 'prompt_version', 'frame_count', 'used_transcript', 'input_tokens',
        'output_tokens', 'cost_usd', 'duration_ms', 'confidence', 'result', 'raw_output', 'repair_attempts', 'error',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'used_transcript' => 'boolean',
            'cost_usd' => 'float',
            'confidence' => 'float',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(AiContent::class);
    }
}
