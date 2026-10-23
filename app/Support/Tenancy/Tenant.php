<?php

namespace App\Support\Tenancy;

use App\Models\Workspace;
use Closure;

/**
 * The workspace the current request, job or command iteration works for.
 * Every model using BelongsToWorkspace is filtered to it and new records
 * are stamped with it. Null means "no tenant": only the scheduler, queue
 * bootstrapping and the super-admin panel run like that.
 */
final class Tenant
{
    private static ?int $id = null;

    private static ?Workspace $workspace = null;

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function workspace(): ?Workspace
    {
        if (self::$id !== null && self::$workspace?->id !== self::$id) {
            self::$workspace = Workspace::find(self::$id);
        }

        return self::$id === null ? null : self::$workspace;
    }

    public static function set(Workspace|int|null $workspace): void
    {
        self::$workspace = $workspace instanceof Workspace ? $workspace : null;
        self::$id = $workspace instanceof Workspace ? $workspace->id : $workspace;
    }

    public static function forget(): void
    {
        self::set(null);
    }

    /**
     * Run $callback as $workspace, restoring the previous tenant afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Workspace|int|null $workspace, Closure $callback): mixed
    {
        [$previousId, $previousWorkspace] = [self::$id, self::$workspace];
        self::set($workspace);

        try {
            return $callback();
        } finally {
            [self::$id, self::$workspace] = [$previousId, $previousWorkspace];
        }
    }

    /**
     * Run $callback once per active (not suspended) workspace.
     *
     * @param  Closure(Workspace): mixed  $callback
     */
    public static function each(Closure $callback): void
    {
        foreach (Workspace::active()->orderBy('id')->get() as $workspace) {
            self::run($workspace, fn () => $callback($workspace));
        }
    }
}
