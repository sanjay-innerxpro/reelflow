<?php

namespace App\Console\Commands;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Services\Drive\DriveImporter;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

class ImportDrive extends Command
{
    protected $signature = 'reels:import-drive
        {--refresh : List every folder now instead of every DRIVE_LIST_EVERY_MINUTES}
        {--limit= : Maximum files to download in this run}';

    protected $description = 'Import new videos from the Google Drive folders (runs from the scheduler)';

    public function handle(DriveImporter $importer): int
    {
        $started = microtime(true);
        $limit = (int) ($this->option('limit') ?: config('reelflow.drive.batch'));
        $listBefore = now()->subMinutes(config('reelflow.drive.list_every_minutes'));
        $counts = [];

        Tenant::each(function () use ($importer, $started, $limit, $listBefore, &$counts) {
            foreach (DriveFolder::where('enabled', true)->get() as $folder) {
                if (! $this->option('refresh') && $folder->last_listed_at?->gt($listBefore)) {
                    continue;
                }
                try {
                    $new = $importer->refresh($folder);
                    $this->line("{$folder->name}: {$new} new file(s).");
                } catch (Throwable $e) {
                    $this->error("{$folder->name}: {$e->getMessage()}");
                }
            }

            $pending = DriveFile::where('status', DriveFile::PENDING)
                ->whereHas('folder', fn ($q) => $q->where('enabled', true))
                ->orderBy('attempts')->orderBy('id')
                ->limit($limit)->get();

            foreach ($pending as $file) {
                if (microtime(true) - $started > config('reelflow.drive.max_seconds')) {
                    return;
                }
                $status = $importer->import($file);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if ($status === DriveImporter::QUOTA) {
                    $this->warn($file->error);

                    return; // this workspace is full; the others still import
                }
                if ($status !== DriveFile::IMPORTED && $status !== DriveFile::DUPLICATE) {
                    $this->warn("{$file->name}: {$status} ({$file->error})");
                }
            }
        });

        $summary = collect($counts)->map(fn ($n, $status) => "{$n} {$status}")->implode(', ');
        $this->info($summary !== '' ? "Drive import: {$summary}." : 'Drive import: nothing to download.');

        return self::SUCCESS;
    }
}
