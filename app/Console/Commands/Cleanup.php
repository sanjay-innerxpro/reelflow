<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\PostStatus;
use App\Enums\UploadSessionStatus;
use App\Enums\VideoStatus;
use App\Models\SocialAccount;
use App\Models\UploadBatch;
use App\Models\UploadSession;
use App\Models\Video;
use App\Services\Media\PlatformRequirements;
use App\Services\Storage\VideoStorage;
use App\Services\Sync\UploadService;
use App\Services\Uploads\BatchUploadService;
use App\Support\ActivityLogger;
use App\Support\Settings;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class Cleanup extends Command
{
    protected $signature = 'reels:cleanup {--dry-run}';

    protected $description = 'Remove expired upload sessions, temp files and (if configured) originals that are safe to delete';

    public function handle(UploadService $uploads, BatchUploadService $batchUploads, VideoStorage $storage, ActivityLogger $activity, PlatformRequirements $requirements, Settings $settings): int
    {
        $dry = (bool) $this->option('dry-run');

        $expired = UploadSession::whereIn('status', [UploadSessionStatus::Open->value, UploadSessionStatus::Failed->value])
            ->where('expires_at', '<', now())->get();
        foreach ($expired as $session) {
            if (! $dry) {
                $uploads->deleteChunks($session);
                $session->update(['status' => UploadSessionStatus::Expired]);
            }
        }
        $this->line("Expired upload sessions: {$expired->count()}");

        // Browser uploads abandoned before completion keep chunks on disk.
        $batches = UploadBatch::where('status', UploadBatch::UPLOADING)->where('expires_at', '<', now())->get();
        foreach ($batches as $batch) {
            if (! $dry) {
                $batchUploads->discardChunks($batch);
                $batch->update(['status' => UploadBatch::EXPIRED]);
            }
        }
        $this->line("Expired browser uploads: {$batches->count()}");

        $tmp = storage_path('app/tmp');
        $old = 0;
        if (is_dir($tmp)) {
            foreach (File::allFiles($tmp) as $file) {
                if ($file->getMTime() < now()->subDay()->getTimestamp()) {
                    $old++;
                    $dry || @unlink($file->getPathname());
                }
            }
        }
        $this->line("Stale temp files: {$old}");

        $days = config('reelflow.storage.cleanup_published_after_days');
        if ($days !== null) {
            Tenant::each(fn () => $this->deleteFinishedOriginals($days, $dry, $storage, $activity, $requirements, $settings));
        }

        return self::SUCCESS;
    }

    private function deleteFinishedOriginals(int $days, bool $dry, VideoStorage $storage, ActivityLogger $activity, PlatformRequirements $requirements, Settings $settings): void
    {
        // Only originals whose every post is finished and that are not reusable.
        $videos = Video::where('status', VideoStatus::Ready->value)
            ->whereNull('storage_deleted_at')
            ->where('allow_reuse', false)
            ->whereHas('scheduledPosts')
            ->whereDoesntHave('scheduledPosts', fn ($q) => $q->where(fn ($q) => $q
                ->whereNotIn('status', [PostStatus::Published->value, PostStatus::Cancelled->value])
                ->orWhere('published_at', '>', now()->subDays($days))))
            ->limit(500)->get();

        foreach ($videos as $video) {
            if (! $this->doneEverywhere($video, $requirements, $settings)) {
                continue;
            }
            $this->line("Delete original: {$video->original_filename}");
            if (! $dry) {
                $storage->delete($video->storage_key, $video->storage_disk);
                $video->update(['storage_deleted_at' => now()]);
                $activity->log('video.storage_deleted', $video, "Original removed after {$days} days (all posts published)");
            }
        }
    }

    /**
     * Keep the original while any enabled account that could still post it
     * has not done so (cross-posting mode), so cleanup never blocks a pending post.
     */
    private function doneEverywhere(Video $video, PlatformRequirements $requirements, Settings $settings): bool
    {
        if (! $settings->bool('publishing.cross_post_all')) {
            return true;
        }

        $finished = $video->scheduledPosts()
            ->whereIn('status', [PostStatus::Published->value, PostStatus::Cancelled->value])
            ->pluck('social_account_id')->all();

        return SocialAccount::whereHas('postingRule', fn ($q) => $q->where('enabled', true))
            ->where('status', '!=', AccountStatus::Disconnected->value)
            ->get()
            ->filter(fn (SocialAccount $a) => $requirements->problems($video, $a->platform) === [])
            ->every(fn (SocialAccount $a) => in_array($a->id, $finished, true));
    }
}
