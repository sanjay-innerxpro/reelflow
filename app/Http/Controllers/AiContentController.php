<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Http\Requests\UpdateAiContentRequest;
use App\Models\AiContent;
use App\Models\Video;
use App\Services\Ai\CaptionValidator;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Content Studio: manual edits, approval and rejection of captions.
 */
class AiContentController extends Controller
{
    public function update(UpdateAiContentRequest $request, Video $video, string $platform, CaptionValidator $validator, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('update', $video);
        $platformEnum = Platform::from($platform);
        $data = $request->validated();

        $hashtags = $validator->hashtags(preg_split('/[\s,]+/', (string) ($data['hashtags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
        if ($platformEnum === Platform::Instagram && count($hashtags) > config('reelflow.meta.instagram_max_hashtags')) {
            return back()->withErrors(['hashtags' => 'Instagram allows at most '.config('reelflow.meta.instagram_max_hashtags').' hashtags.'])->withInput();
        }
        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn ($t) => trim($t))->filter()->unique()->values()->all();

        DB::transaction(function () use ($video, $platformEnum, $data, $hashtags, $tags, $request) {
            $current = $video->currentContent($platformEnum);
            $approve = $request->boolean('approve');

            $attributes = [
                'title' => $platformEnum === Platform::YouTube ? $data['title'] : null,
                'caption' => $platformEnum === Platform::YouTube ? ($data['description'] ?? '') : $data['caption'],
                'description' => $platformEnum === Platform::YouTube ? ($data['description'] ?? '') : null,
                'hashtags' => $hashtags,
                'tags' => $platformEnum === Platform::YouTube ? $tags : null,
                'validation_status' => $approve ? ContentStatus::Approved : ContentStatus::Edited,
                'approved_by' => $approve ? $request->user()->id : null,
                'approved_at' => $approve ? now() : null,
                'edited_at' => now(),
            ];

            if ($current) {
                $current->update($attributes);
            } else {
                AiContent::create(array_merge($attributes, [
                    'video_id' => $video->id,
                    'platform' => $platformEnum,
                    'provider' => 'manual',
                    'is_current' => true,
                    'language' => $video->language,
                ]));
            }
        });

        $activity->log('content.edited', $video, "Edited {$platformEnum->label()} caption".($request->boolean('approve') ? ' and approved' : ''));

        return back()->with('status', $platformEnum->label().' caption saved.');
    }

    public function approve(Request $request, Video $video, string $platform, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('update', $video);
        $content = $video->currentContent(Platform::from($platform));
        abort_unless($content, 404);

        $content->update(['validation_status' => ContentStatus::Approved, 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        $activity->log('content.approved', $video, "Approved {$content->platform->label()} caption");

        return back()->with('status', 'Caption approved.');
    }

    public function reject(Video $video, string $platform, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('update', $video);
        $content = $video->currentContent(Platform::from($platform));
        abort_unless($content, 404);

        $content->update(['validation_status' => ContentStatus::Rejected, 'approved_by' => null, 'approved_at' => null]);
        $activity->log('content.rejected', $video, "Rejected {$content->platform->label()} caption");

        return back()->with('status', 'Caption rejected; it will not be published.');
    }
}
