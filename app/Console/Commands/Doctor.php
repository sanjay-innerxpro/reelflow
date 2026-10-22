<?php

namespace App\Console\Commands;

use App\Support\SystemCheck;
use Illuminate\Console\Command;

class Doctor extends Command
{
    protected $signature = 'reels:doctor {--json : Machine-readable output}';

    protected $description = 'Check production readiness: infrastructure, configuration and missing credentials';

    public function handle(SystemCheck $system): int
    {
        $checks = $system->all();
        $overall = SystemCheck::overall($checks);

        if ($this->option('json')) {
            $this->line(json_encode(['status' => $overall, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $overall === SystemCheck::FAIL ? self::FAILURE : self::SUCCESS;
        }

        $icons = [SystemCheck::OK => '<fg=green>✔</>', SystemCheck::WARN => '<fg=yellow>!</>', SystemCheck::FAIL => '<fg=red>✘</>'];
        foreach ($checks as $check) {
            $this->line(sprintf(' %s %-32s %s', $icons[$check['status']], $check['label'], $check['message']));
        }

        $this->newLine();
        match ($overall) {
            SystemCheck::OK => $this->info('Everything is ready.'),
            SystemCheck::WARN => $this->warn('Running, with items to complete (warnings above).'),
            default => $this->error('Blocking problems found (✘ above).'),
        };

        return $overall === SystemCheck::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
