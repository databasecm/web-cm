<?php

namespace App\Policies;

use App\Models\Bast;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for BAST (Fase 3-2), composed from {@see ProjectPolicy} against
 * the parent project:
 *
 * - Issue / sign as the company: staff who manage the project (Owner/Direktur
 *   anywhere, a Manager in its own bidang).
 * - Sign as the customer: the owning consumer.
 * - View: anyone who may view the parent project (both parties + financing Mitra).
 *
 * The full UI/API endpoints land in Fase 3-3; these gates are ready for them.
 */
class BastPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function view(User $actor, Bast $bast): bool
    {
        $project = $bast->project;

        return $project !== null && $this->projects->view($actor, $project);
    }

    /**
     * Issue the draft BAST — a project-management action (no Bast instance yet),
     * exposed via the `issueBast` gate.
     */
    public function issue(User $actor, Project $project): bool
    {
        return $this->projects->update($actor, $project);
    }

    public function signCompany(User $actor, Bast $bast): bool
    {
        $project = $bast->project;

        return $project !== null && $this->projects->update($actor, $project);
    }

    public function signCustomer(User $actor, Bast $bast): bool
    {
        $project = $bast->project;

        return $project !== null
            && $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $project->konsumen_id === (int) $actor->getKey();
    }

    /**
     * Download the BAST document PDF (Fase 3-7). Only a signed BAST is a valid
     * handover document; anyone who may view the project — the owning consumer,
     * the Manager of its bidang, or the financing Mitra (read-only) — may
     * download it.
     */
    public function downloadPdf(User $actor, Bast $bast): bool
    {
        return $bast->isSigned() && $this->view($actor, $bast);
    }
}
