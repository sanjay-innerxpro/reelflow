<?php

namespace App\Console\Commands\Concerns;

use App\Models\Workspace;

/**
 * For commands that act on one workspace: --workspace=<id>, optional when
 * only one workspace exists.
 */
trait ResolvesWorkspace
{
    protected function workspace(): ?Workspace
    {
        $option = $this->option('workspace');
        if ($option !== null) {
            $workspace = Workspace::find($option);
            if (! $workspace) {
                $this->error("Workspace {$option} not found.");
            }

            return $workspace;
        }

        if (Workspace::count() === 1) {
            return Workspace::first();
        }

        $this->error('Pass --workspace=<id>. Workspaces: '.Workspace::orderBy('id')->get()->map(fn ($w) => "{$w->id} ({$w->name})")->implode(', '));

        return null;
    }
}
