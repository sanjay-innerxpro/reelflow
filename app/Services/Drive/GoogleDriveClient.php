<?php

namespace App\Services\Drive;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lists and downloads the files of a Google Drive folder shared as "Anyone
 * with the link". Uses the official Drive API when GOOGLE_DRIVE_API_KEY is
 * set, otherwise Drive's public folder view and download endpoint.
 */
class GoogleDriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3/files';

    /**
     * Folder ID from a folder link (…/folders/<id>, ?id=<id>) or a bare ID.
     */
    public static function folderIdFrom(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $input, $m) || preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $input, $m)) {
            return $m[1];
        }

        return preg_match('~^[A-Za-z0-9_-]{10,100}$~', $input) ? $input : null;
    }

    /**
     * @return list<array{id: string, name: string, size: ?int}>
     */
    public function listFolder(string $folderId): array
    {
        return config('reelflow.drive.api_key') ? $this->listWithApi($folderId) : $this->listPublic($folderId);
    }

    /**
     * Stream a file to $path. Throws when Drive answers with anything but the file.
     */
    public function download(string $fileId, string $path): void
    {
        $key = config('reelflow.drive.api_key');
        $url = $key
            ? self::API.'/'.rawurlencode($fileId).'?'.http_build_query(['alt' => 'media', 'key' => $key, 'supportsAllDrives' => 'true'])
            : 'https://drive.usercontent.google.com/download?'.http_build_query(['id' => $fileId, 'export' => 'download', 'confirm' => 't']);

        try {
            $response = Http::withOptions(['sink' => $path])->timeout(config('reelflow.drive.timeout'))->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Google Drive: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Google Drive returned HTTP {$response->status()} for the download.");
        }
        if (str_contains(strtolower($response->header('Content-Type')), 'text/html')) {
            // A sign-in, quota or "can't scan for viruses" page instead of the file.
            throw new RuntimeException('Google Drive returned a web page instead of the file (is the file shared as "Anyone with the link"? Too many downloads today?).');
        }
    }

    /**
     * @return list<array{id: string, name: string, size: ?int}>
     */
    private function listWithApi(string $folderId): array
    {
        $files = [];
        $pageToken = null;

        do {
            $response = $this->get(self::API, array_filter([
                'q' => "'{$folderId}' in parents and trashed = false and mimeType contains 'video/'",
                'fields' => 'nextPageToken, files(id, name, size)',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
                'key' => config('reelflow.drive.api_key'),
            ]));
            foreach ($response->json('files', []) as $file) {
                $files[] = ['id' => $file['id'], 'name' => $file['name'], 'size' => isset($file['size']) ? (int) $file['size'] : null];
            }
            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $files;
    }

    /**
     * @return list<array{id: string, name: string, size: ?int}>
     */
    private function listPublic(string $folderId): array
    {
        $html = $this->get('https://drive.google.com/embeddedfolderview', ['id' => $folderId])->body();

        preg_match_all('~<a href="https://drive\.google\.com/file/d/([A-Za-z0-9_-]+)/[^"]*"[^>]*>.*?<div class="flip-entry-title">(.*?)</div>~s', $html, $matches, PREG_SET_ORDER);
        if ($matches === [] && ! str_contains($html, 'flip-entries')) {
            throw new RuntimeException('Could not read the folder. Share it as "Anyone with the link" (Viewer) in Google Drive.');
        }

        $files = [];
        foreach ($matches as [, $id, $title]) {
            $files[$id] = ['id' => $id, 'name' => trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5)), 'size' => null];
        }

        return array_values($files);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $url, array $query): Response
    {
        try {
            $response = Http::timeout(60)
                ->retry(2, 2000, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Google Drive: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404) {
            throw new RuntimeException('Folder not found. Check the link and that it is shared as "Anyone with the link".');
        }
        if ($response->failed()) {
            $message = $response->json('error.message') ?? 'HTTP '.$response->status();
            throw new RuntimeException("Google Drive error: {$message}");
        }

        return $response;
    }
}
