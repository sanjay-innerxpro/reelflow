<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiContent extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'video_id', 'ai_generation_id', 'platform', 'title', 'caption', 'description', 'hashtags', 'tags', 'language',
        'provider', 'model', 'prompt_version', 'confidence', 'validation_status', 'content_warnings', 'is_current',
        'approved_by', 'approved_at', 'edited_at', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'validation_status' => ContentStatus::class,
            'hashtags' => 'array',
            'tags' => 'array',
            'content_warnings' => 'array',
            'confidence' => 'float',
            'is_current' => 'boolean',
            'approved_at' => 'datetime',
            'edited_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Caption text with hashtags appended, as posted to Facebook/Instagram.
     */
    public function fullCaption(): string
    {
        $tags = collect($this->hashtags ?? [])->map(fn ($t) => '#'.ltrim((string) $t, '#'))->implode(' ');

        return trim(trim((string) $this->caption)."\n\n".$tags);
    }

    /**
     * YouTube description with hashtags appended.
     */
    public function fullDescription(): string
    {
        $tags = collect($this->hashtags ?? [])->map(fn ($t) => '#'.ltrim((string) $t, '#'))->implode(' ');

        return trim(trim((string) ($this->description ?? $this->caption))."\n\n".$tags);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }
}
