<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesWorkspace;
use App\Models\DriveFolder;
use App\Services\Drive\GoogleDriveClient;
use App\Services\Sync\PathSanitizer;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class AddDriveFolder extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'reels:drive-add {link : Folder link shared as "Anyone with the link"} {--name=Google Drive : Library folder name for its videos} {--workspace= : Workspace ID (optional with one workspace)}';

    protected $description = 'Add a Google Drive folder to import videos from';

    public function handle(): int
    {
        $id = GoogleDriveClient::folderIdFrom((string) $this->argument('link'));
        if ($id === null) {
            $this->error('That does not look like a Google Drive folder link.');

            return self::FAILURE;
        }

        if (! $workspace = $this->workspace()) {
            return self::FAILURE;
        }
        $folder = Tenant::run($workspace, fn () => DriveFolder::updateOrCreate(['folder_id' => $id], ['name' => PathSanitizer::label((string) $this->option('name')), 'enabled' => true]));
        $this->info("Drive folder \"{$folder->name}\" ({$id}) added. The next reels:import-drive run imports its videos.");

        return self::SUCCESS;
    }
}
