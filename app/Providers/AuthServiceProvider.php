<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the RBAC authorization layer: the user account policy plus the
 * `assign-account` gate used by the store/update Form Requests.
 *
 * (Laravel 11 folds the old AuthServiceProvider into AppServiceProvider; this
 * dedicated provider keeps the RBAC wiring discoverable in one place.)
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        // Whether the actor may assign the given role + bidang to an account.
        // Backed by UserPolicy::assign so create/update share one rule set.
        Gate::define('assign-account', [UserPolicy::class, 'assign']);
    }
}
