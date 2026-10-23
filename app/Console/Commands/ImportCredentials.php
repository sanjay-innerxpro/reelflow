<?php

namespace App\Console\Commands;

use App\Support\Credentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ImportCredentials extends Command
{
    protected $signature = 'reels:import-credentials {--once : Do nothing if an import has run before (used by the deploy scripts)}';

    protected $description = 'Copy the platform credentials set in .env into Admin → Credentials (fields already saved there are kept)';

    public function handle(Credentials $credentials): int
    {
        if ($this->option('once') && $credentials->wasImportedFromEnv()) {
            $this->line('Credentials were imported from .env before; nothing to do.');

            return self::SUCCESS;
        }

        $imported = $credentials->importFromEnv();
        if ($imported === []) {
            $this->info('Nothing to import: .env sets no credential that is not already saved in Admin → Credentials.');

            return self::SUCCESS;
        }

        $this->info('Imported into Admin → Credentials: '.implode(', ', $imported));
        rescue(fn () => Artisan::call('queue:restart'), report: false);

        return self::SUCCESS;
    }
}
