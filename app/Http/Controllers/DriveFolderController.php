<?php

namespace App\Http\Controllers;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Services\Drive\GoogleDriveClient;
use App\Services\Sync\PathSanitizer;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DriveFolderController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(): View
    {
        $counts = DriveFile::selectRaw('drive_folder_id, status, count(*) as n')->groupBy('drive_folder_id', 'status')->get()
            ->groupBy('drive_folder_id')->map(fn ($rows) => $rows->pluck('n', 'status'));

        return view('drive.index', [
            'folders' => DriveFolder::latest()->get(),
            'counts' => $counts,
            'failed' => DriveFile::with('folder')->whereIn('status', [DriveFile::FAILED, DriveFile::SKIPPED])->latest('updated_at')->limit(20)->get(),
            'apiKey' => filled(config('reelflow.drive.api_key')),
            'batch' => config('reelflow.drive.batch'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'link' => ['required', 'string', 'max:500'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $id = GoogleDriveClient::folderIdFrom($data['link']);
        if ($id === null) {
            return back()->withErrors(['link' => 'Paste a Google Drive folder link (https://drive.google.com/drive/folders/…).'])->withInput();
        }

        $folder = DriveFolder::updateOrCreate(['folder_id' => $id], ['name' => PathSanitizer::label($data['name']), 'enabled' => true, 'last_listed_at' => null]);
        $this->activity->log('drive.folder_added', $folder, "Google Drive folder {$folder->name} added");

        return redirect()->route('drive.index')->with('status', "Folder \"{$folder->name}\" added. Its videos are imported within 5 minutes.");
    }

    public function toggle(DriveFolder $folder): RedirectResponse
    {
        $folder->update(['enabled' => ! $folder->enabled]);
        $this->activity->log('drive.folder_toggled', $folder, $folder->enabled ? 'Import resumed' : 'Import paused');

        return back()->with('status', "\"{$folder->name}\" ".($folder->enabled ? 'resumed' : 'paused').'.');
    }

    /**
     * List the folder again on the next run and retry its failed files.
     */
    public function refresh(DriveFolder $folder): RedirectResponse
    {
        $folder->update(['last_listed_at' => null]);
        $retried = $folder->files()->where('status', DriveFile::FAILED)->update(['status' => DriveFile::PENDING, 'attempts' => 0, 'error' => null]);

        return back()->with('status', "\"{$folder->name}\" is checked for new videos within 5 minutes".($retried ? "; {$retried} failed file(s) will be retried" : '').'.');
    }

    public function destroy(DriveFolder $folder): RedirectResponse
    {
        $folder->delete();
        $this->activity->log('drive.folder_removed', null, "Google Drive folder {$folder->name} removed");

        return redirect()->route('drive.index')->with('status', "\"{$folder->name}\" removed. Videos already imported stay in the library.");
    }
}
