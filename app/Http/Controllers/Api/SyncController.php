<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentMediaRequest;
use App\Http\Requests\HeartbeatRequest;
use App\Http\Requests\RegisterVideoRequest;
use App\Models\SyncDevice;
use App\Models\UploadSession;
use App\Models\Video;
use App\Services\Media\AgentMediaService;
use App\Services\Sync\UploadException;
use App\Services\Sync\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validated();

        $device->forceFill([
            'agent_version' => $data['agent_version'] ?? $device->agent_version,
            'platform_info' => $data['platform'] ?? $device->platform_info,
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'offline_notified_at' => null,
            'last_error' => $data['last_error'] ?? null,
            'last_error_at' => ! empty($data['last_error']) ? now() : $device->last_error_at,
        ])->save();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'device' => ['id' => $device->id, 'name' => $device->name],
            'config' => [
                'media_processing' => config('reelflow.media.processing'),
                'ai_frames' => config('reelflow.ai.max_frames'),
                'frame_width' => config('reelflow.ai.frame_width'),
                'thumbnail_width' => config('reelflow.media.agent_thumbnail_width'),
                'chunk_size_bytes' => config('reelflow.uploads.chunk_size_mb') * 1024 * 1024,
                'allowed_extensions' => array_values(config('reelflow.uploads.allowed_extensions')),
                'max_file_size_bytes' => config('reelflow.uploads.max_file_size_mb') * 1024 * 1024,
            ],
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'checksums' => ['required', 'array', 'max:500'],
            'checksums.*' => ['string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        $videos = Video::whereIn('checksum_sha256', array_map('strtolower', $data['checksums']))
            ->get()
            ->mapWithKeys(fn (Video $v) => [$v->checksum_sha256 => [
                'video_id' => $v->id,
                'status' => $v->status->value,
                'uploaded' => $v->uploaded_at !== null,
                'needs_media' => $v->needsAgentMedia(),
            ]]);

        return response()->json(['videos' => (object) $videos->all()]);
    }

    public function register(RegisterVideoRequest $request): JsonResponse
    {
        try {
            $result = $this->uploads->register($this->device($request), $request->validated());
        } catch (UploadException $e) {
            return $this->error($e);
        }

        $video = $result['video'];

        return response()->json([
            'video' => [
                'id' => $video->id,
                'status' => $video->status->value,
                'uploaded' => $video->uploaded_at !== null,
                'duplicate' => $result['duplicate'],
                'needs_media' => $video->needsAgentMedia(),
            ],
            'upload' => $result['session'] ? $this->sessionPayload($result['session']) : null,
        ], $result['duplicate'] ? 200 : 201);
    }

    public function showVideo(Request $request, Video $video): JsonResponse
    {
        $device = $this->device($request);
        abort_unless($video->sources()->where('sync_device_id', $device->id)->exists(), 404);

        return response()->json(['video' => [
            'id' => $video->id,
            'status' => $video->status->value,
            'uploaded' => $video->uploaded_at !== null,
            'needs_media' => $video->needsAgentMedia(),
            'processing_error' => $video->processing_error,
        ]]);
    }

    /**
     * Metadata, thumbnail and AI frames from the agent's local FFmpeg
     * (MEDIA_PROCESSING=agent).
     */
    public function media(AgentMediaRequest $request, Video $video, AgentMediaService $media): JsonResponse
    {
        $device = $this->device($request);
        abort_unless($video->sources()->where('sync_device_id', $device->id)->exists(), 404);

        $video = $media->store($video, $request->metadata(), $request->file('thumbnail'), $request->file('frames', []));

        return response()->json(['video' => [
            'id' => $video->id,
            'status' => $video->status->value,
            'needs_media' => $video->needsAgentMedia(),
            'frames' => count($video->ai_frame_keys ?? []),
        ]]);
    }

    public function showUpload(Request $request, string $session): JsonResponse
    {
        return response()->json(['upload' => $this->sessionPayload($this->session($request, $session))]);
    }

    public function chunk(Request $request, string $session, int $index): JsonResponse
    {
        $upload = $this->session($request, $session);
        $sha = (string) $request->header('X-Chunk-Sha256');
        if (! preg_match('/^[a-fA-F0-9]{64}$/', $sha)) {
            return response()->json(['error' => 'missing_checksum', 'message' => 'X-Chunk-Sha256 header is required.'], 422);
        }

        try {
            $this->uploads->storeChunk($upload, $index, $request->getContent(true), $sha);
        } catch (UploadException $e) {
            return $this->error($e);
        }

        return response()->json([
            'chunk_index' => $index,
            'received_count' => $upload->chunks()->count(),
            'total_chunks' => $upload->total_chunks,
        ]);
    }

    public function complete(Request $request, string $session): JsonResponse
    {
        try {
            $upload = $this->uploads->complete($this->session($request, $session));
        } catch (UploadException $e) {
            return $this->error($e);
        }

        return response()->json(['upload' => $this->sessionPayload($upload)], 202);
    }

    public function abort(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $upload = $this->session($request, $session);
        if ($upload->isOpen()) {
            $this->uploads->reportFailure($upload, $data['reason']);
        }

        return response()->json(['upload' => $this->sessionPayload($upload->refresh())]);
    }

    private function device(Request $request): SyncDevice
    {
        return $request->attributes->get('device');
    }

    private function session(Request $request, string $id): UploadSession
    {
        return UploadSession::where('id', $id)
            ->where('sync_device_id', $this->device($request)->id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(UploadSession $session): array
    {
        return [
            'session_id' => $session->id,
            'status' => $session->status->value,
            'video_id' => $session->video_id,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => $session->receivedChunkIndexes(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'error' => $session->error,
        ];
    }

    private function error(UploadException $e): JsonResponse
    {
        return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
    }
}
