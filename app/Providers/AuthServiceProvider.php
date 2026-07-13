<?php

namespace App\Providers;

use App\Models\Ahsap;
use App\Models\Attendance;
use App\Models\Bast;
use App\Models\Consultation;
use App\Models\Design;
use App\Models\Employee;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Installment;
use App\Models\Material;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\AhsapPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\BastPolicy;
use App\Policies\ConsultationPolicy;
use App\Policies\DealPolicy;
use App\Policies\DesignPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\FinancingDocumentPolicy;
use App\Policies\FinancingPolicy;
use App\Policies\InstallmentPolicy;
use App\Policies\MaterialPolicy;
use App\Policies\PaymentPolicy;
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
        Gate::policy(Bast::class, BastPolicy::class);
        Gate::policy(Installment::class, InstallmentPolicy::class);
        Gate::policy(Financing::class, FinancingPolicy::class);
        Gate::policy(FinancingDocument::class, FinancingDocumentPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);

        // Whether the actor may assign the given role + bidang to an account.
        // Backed by UserPolicy::assign so create/update share one rule set.
        Gate::define('assign-account', [UserPolicy::class, 'assign']);

        // Narrow, context-bound deal forward-creations (ADR-0001/0003).
        // Intentionally NOT part of UserPolicy/ProjectPolicy so the general
        // account-management hierarchy is never widened.
        Gate::define('createCustomerForDeal', [DealPolicy::class, 'createCustomer']);
        Gate::define('createProjectForDeal', [DealPolicy::class, 'createProject']);

        // Issuing a BAST is a project-management action (no Bast instance yet),
        // so it is a project-scoped gate rather than a BastPolicy model ability.
        Gate::define('issueBast', [BastPolicy::class, 'issue']);

        // Recording a consumer payment into the cash book: Finance + Owner/Direktur
        // (segregation of duties — the biller is not the cash recorder, §6.3).
        Gate::define('recordPayment', [PaymentPolicy::class, 'record']);

        // Applying for financing on a project (no Financing instance yet) — the
        // owning consumer. Full application flow lands in Fase 4-5.
        Gate::define('applyFinancing', [FinancingPolicy::class, 'apply']);

        // Uploading a document for a financing (no document instance yet) — the
        // owning consumer.
        Gate::define('uploadFinancingDocument', [FinancingDocumentPolicy::class, 'upload']);

        // Recording attendance for a worker (no Attendance instance yet) — a
        // Mandor in the worker's bidang, or HR/overseers.
        Gate::define('recordAttendance', [AttendancePolicy::class, 'record']);
    }
}
