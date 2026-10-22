<?php

namespace App\Http\Controllers;

use App\Enums\AiStatus;
use App\Enums\Platform;
use App\Enums\VideoStatus;
use App\Jobs\GenerateCaptionsJob;
use App\Jobs\ProcessVideoJob;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Ai\AiProviderFactory;
use App\Services\Media\PlatformRequirements;
use App\Services\Publishing\ContentResolver;
use App\Services\Storage\VideoStorage;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VideoController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::enum(VideoStatus::class)],
            'ai' => ['nullable', Rule::enum(AiStatus::class)],
            'posted' => ['nullable', Rule::in(['never', 'any'])],
            'duplicates' => ['nullable', 'boolean'],
        ]);

        $videos = Video::query()
            ->withCount(['scheduledPosts', 'sources'])
            ->search($filters['q'] ?? null)
            ->when($filters['folder'] ?? null, fn ($q, $f) => $q->where(fn ($q) => $q->where('folder', $f)->orWhere('folder', 'like', str_replace(['%', '_'], ['\%', '\_'], $f).'/%')))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['ai'] ?? null, fn ($q, $s) => $q->where('ai_status', $s))
            ->when(($filters['posted'] ?? null) === 'never', fn ($q) => $q->doesntHave('scheduledPosts'))
            ->when(($filters['posted'] ?? null) === 'any', fn ($q) => $q->has('scheduledPosts'))
            ->when($filters['duplicates'] ?? false, fn ($q) => $q->has('sources', '>', 1))
            ->latest('id')
            ->paginate(24)
            ->withQueryString();

        $folders = Video::query()->select('folder')->distinct()->orderBy('folder')->pluck('folder');

        return view('videos.index', [
            'videos' => $videos,
            'folders' => $folders,
            'filters' => $filters,
            'accounts' => SocialAccount::orderBy('platform')->get(),
        ]);
    }

    public function show(Video $video, VideoStorage $storage, PlatformRequirements $requirements, ContentResolver $contents, AiProviderFactory $ai): View
    {
        Gate::authorize('view', $video);
        $video->load(['sources.syncDevice', 'scheduledPosts.socialAccount', 'aiGenerations' => fn ($q) => $q->latest()->limit(5)]);

        $content = [];
        foreach (Platform::cases() as $platform) {
            $item = $video->currentContent($platform);
            $content[$platform->value] = [
                'model' => $item,
                'problem' => $contents->problem($item),
                'format' => $requirements->problems($video, $platform),
            ];
        }

        return view('videos.show', [
            'video' => $video,
            'content' => $content,
            'thumbnail' => $storage->thumbnailUrl($video),
            'accounts' => SocialAccount::orderBy('platform')->get(),
            'aiConfigured' => $ai->isConfigured(),
        ]);
    }

    public function update(Request $request, Video $video, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('update', $video);
        $data = $request->validate(['allow_reuse' => ['boolean'], 'category' => ['nullable', 'string', 'max:100']]);
        $video->update(['allow_reuse' => $request->boolean('allow_reuse'), 'category' => $data['category'] ?? $video->category]);
        $activity->log('video.updated', $video, 'Video settings updated');

        return back()->with('status', 'Video updated.');
    }

    public function media(Video $video, VideoStorage $storage): RedirectResponse
    {
        Gate::authorize('view', $video);
        abort_unless($video->hasStoredFile(), 404);

        return redirect()->away($storage->temporaryUrl($video, now()->addMinutes(30)));
    }

    public function thumbnail(Video $video, VideoStorage $storage): RedirectResponse
    {
        Gate::authorize('view', $video);
        $url = $storage->thumbnailUrl($video, 60);
        abort_unless($url, 404);

        return redirect()->away($url);
    }

    public function reprocess(Video $video, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('update', $video);
        if (! $video->storage_key || $video->storage_deleted_at) {
            return back()->withErrors(['video' => 'The original file is not in storage; it must be uploaded again by the sync agent.']);
        }

        ProcessVideoJob::dispatch($video->id);
        $activity->log('video.reprocess', $video, 'Reprocessing requested');

        return back()->with('status', 'Reprocessing queued.');
    }

    public function generate(Video $video, AiProviderFactory $ai): RedirectResponse
    {
        Gate::authorize('update', $video);
        if (! $ai->isConfigured()) {
            return back()->withErrors(['ai' => 'No AI provider is configured. Set AI_PROVIDER and AI_API_KEY in the server environment.']);
        }
        if (! $video->hasStoredFile() || ! $video->duration_seconds) {
            return back()->withErrors(['ai' => 'The video must be uploaded and processed first.']);
        }

        $video->update(['ai_status' => AiStatus::Pending]);
        GenerateCaptionsJob::dispatch($video->id);

        return back()->with('status', 'Caption generation queued. Existing captions stay until the new ones are ready.');
    }

    public function bulk(Request $request, AiProviderFactory $ai): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['generate', 'reprocess', 'allow_reuse', 'disallow_reuse'])],
        ]);

        $videos = Video::whereIn('id', $data['ids'])->get()->filter(fn ($v) => Gate::allows('update', $v));
        $count = 0;
        foreach ($videos as $video) {
            switch ($data['action']) {
                case 'generate':
                    if ($ai->isConfigured() && $video->hasStoredFile() && $video->duration_seconds) {
                        $video->update(['ai_status' => AiStatus::Pending]);
                        GenerateCaptionsJob::dispatch($video->id);
                        $count++;
                    }
                    break;
                case 'reprocess':
                    if ($video->storage_key && ! $video->storage_deleted_at) {
                        ProcessVideoJob::dispatch($video->id);
                        $count++;
                    }
                    break;
                default:
                    $video->update(['allow_reuse' => $data['action'] === 'allow_reuse']);
                    $count++;
            }
        }

        return back()->with('status', "Action applied to {$count} video(s).");
    }
}
