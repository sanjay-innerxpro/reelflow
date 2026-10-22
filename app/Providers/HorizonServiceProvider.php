<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        if ($email = config('reelflow.admin_email')) {
            // Long queue waits are emailed to the administrator.
            Horizon::routeMailNotificationsTo($email);
        }
    }

    /**
     * Any authenticated dashboard user may view Horizon (single-tenant app).
     */
    protected function gate(): void
    {
        // Horizon needs Redis; in cron queue mode (shared hosting) it is unused.
        Gate::define('viewHorizon', fn (?User $user = null) => $user !== null && config('reelflow.queue_mode') !== 'cron');
    }
}
