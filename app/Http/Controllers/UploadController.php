<?php

namespace App\Http\Controllers;

use App\Models\UploadBatch;
use App\Services\Sync\UploadException;
use App\Services\Uploads\BatchUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Upload page: ZIPs of videos or single videos, sent in chunks by
 * resources/js/uploader.js.
 */
class UploadController extends Controller
{
    public function __construct(private readonly BatchUploadService $uploads) {}

    public function index(): View
    {
        return view('uploads.index', [
            'batches' => UploadBatch::latest()->limit(20)->get(),
            'limits' => [
                'max_bytes' => config('reelflow.web_uploads.max_mb') * 1024 * 1024,
                'max_mb' => config('reelflow.web_uploads.max_mb'),
                'max_videos' => config('reelflow.web_uploads.max_videos'),
                'extensions' => config('reelflow.uploads.allowed_extensions'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $batch = $this->uploads->create($request->user(), $data['name'], (int) $data['size']);
        } catch (UploadException $e) {
            return $this->error($e);
        }

        return response()->json($this->payload($batch), 201);
    }

    public function show(UploadBatch $batch): JsonResponse
    {
        return response()->json($this->payload($batch));
    }

    public function chunk(Request $request, UploadBatch $batch, int $index): JsonResponse
    {
        try {
            $this->uploads->storeChunk($batch, $index, $request->getContent(true));
        } catch (UploadException $e) {
            return $this->error($e);
        }

        return response()->json(['received' => $index]);
    }

    public function complete(UploadBatch $batch): JsonResponse
    {
        try {
            $batch = $this->uploads->complete($batch);
        } catch (UploadException $e) {
            return $this->error($e);
        }

        return response()->json($this->payload($batch));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UploadBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->original_name,
            'kind' => $batch->kind,
            'status' => $batch->status,
            'chunk_size' => $batch->chunk_size,
            'total_chunks' => $batch->total_chunks,
            'received' => $batch->status === UploadBatch::UPLOADING ? $this->uploads->receivedChunks($batch) : [],
            'added' => $batch->added_count,
            'results' => $batch->results ?? [],
            'error' => $batch->error,
        ];
    }

    private function error(UploadException $e): JsonResponse
    {
        return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
    }
}
