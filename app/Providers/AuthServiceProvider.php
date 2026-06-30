<?php

namespace App\Providers;

use App\Models\Ahsap;
use App\Models\Consultation;
use App\Models\Design;
use App\Models\Material;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\AhsapPolicy;
use App\Policies\ConsultationPolicy;
use App\Policies\DealPolicy;
use App\Policies\DesignPolicy;
use App\Policies\MaterialPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RabPolicy;
use App\Policies\SupplierPolicy;
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
        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(Material::class, MaterialPolicy::class);
        Gate::policy(Ahsap::class, AhsapPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Design::class, DesignPolicy::class);
        Gate::policy(Rab::class, RabPolicy::class);

        // Whether the actor may assign the given role + bidang to an account.
        // Backed by UserPolicy::assign so create/update share one rule set.
        Gate::define('assign-account', [UserPolicy::class, 'assign']);

        // Narrow, context-bound deal forward-creations (ADR-0001/0003).
        // Intentionally NOT part of UserPolicy/ProjectPolicy so the general
        // account-management hierarchy is never widened.
        Gate::define('createCustomerForDeal', [DealPolicy::class, 'createCustomer']);
        Gate::define('createProjectForDeal', [DealPolicy::class, 'createProject']);
    }
}
