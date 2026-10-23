<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant-owned record: queries only see the current workspace's rows and new
 * rows are stamped with it. Creating one with no workspace is a bug, except
 * for models that allow system-wide rows (static $workspaceOptional).
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model) {
            if ($model->workspace_id === null) {
                $model->workspace_id = Tenant::id();
            }
            if ($model->workspace_id === null && ! (static::$workspaceOptional ?? false)) {
                throw new LogicException('Cannot create '.class_basename($model).' without a workspace.');
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Query across all workspaces (scheduler internals, super-admin panel).
     */
    public static function allWorkspaces(): Builder
    {
        return static::withoutGlobalScope(WorkspaceScope::class);
    }
}
