<?php

namespace App\Policies;

use App\Models\Design;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for project designs (Fase 2B-2), kept consistent with
 * {@see ProjectPolicy} by delegating to it against the parent project:
 *
 * - Manage (add version / submit / update / delete): staff who manage the parent
 *   project — Owner/Direktur anywhere, a Manager in its own bidang.
 * - View: anyone who may view the parent project (incl. the owning consumer and,
 *   read-only, the financing Mitra).
 * - Approve: ONLY the owning consumer, and only a submitted version.
 */
class DesignPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function viewAny(User $actor): bool
    {
        return $this->projects->viewAny($actor);
    }

    public function view(User $actor, Design $design): bool
    {
        $project = $design->project;

        return $project !== null && $this->projects->view($actor, $project);
    }

    public function create(User $actor): bool
    {
        return $this->projects->create($actor);
    }

    public function update(User $actor, Design $design): bool
    {
        $project = $design->project;

        return $project !== null && $this->projects->update($actor, $project);
    }

    public function delete(User $actor, Design $design): bool
    {
        $project = $design->project;

        return $project !== null && $this->projects->delete($actor, $project);
    }

    /**
     * Submitting a design to the consumer is a management action.
     */
    public function submit(User $actor, Design $design): bool
    {
        return $this->update($actor, $design);
    }

    /**
     * Only the owning consumer may approve, and only a submitted version.
     */
    public function approve(User $actor, Design $design): bool
    {
        $project = $design->project;

        return $project !== null
            && $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $project->konsumen_id === (int) $actor->getKey()
            && $design->isSubmitted();
    }
}
