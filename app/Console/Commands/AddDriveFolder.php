<?php

namespace App\Console\Commands;

use App\Models\DriveFolder;
use App\Services\Drive\GoogleDriveClient;
use App\Services\Sync\PathSanitizer;
use Illuminate\Console\Command;

class AddDriveFolder extends Command
{
    protected $signature = 'reels:drive-add {link : Folder link shared as "Anyone with the link"} {--name=Google Drive : Library folder name for its videos}';

    protected $description = 'Add a Google Drive folder to import videos from';

    public function handle(): int
    {
        $id = GoogleDriveClient::folderIdFrom((string) $this->argument('link'));
        if ($id === null) {
            $this->error('That does not look like a Google Drive folder link.');

            return self::FAILURE;
        }

        $folder = DriveFolder::updateOrCreate(['folder_id' => $id], ['name' => PathSanitizer::label((string) $this->option('name')), 'enabled' => true]);
        $this->info("Drive folder \"{$folder->name}\" ({$id}) added. The next reels:import-drive run imports its videos.");

        return self::SUCCESS;
    }
}
