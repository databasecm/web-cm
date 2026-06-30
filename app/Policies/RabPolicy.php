<?php

namespace App\Policies;

use App\Enums\RabStatus;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for RABs (Fase 2B-4), consistent with {@see ProjectPolicy} by
 * delegating to it against the parent project:
 *
 * - Manage (build / update / delete / submit): staff who manage the parent
 *   project — Owner/Direktur anywhere, a Manager in its own bidang.
 * - View: anyone who may view the parent project (consumer owner; financing
 *   Mitra read-only).
 * - Approve: ONLY the owning consumer, and only a submitted version.
 */
class RabPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function viewAny(User $actor): bool
    {
        return $this->projects->viewAny($actor);
    }

    public function view(User $actor, Rab $rab): bool
    {
        $project = $rab->project;

        return $project !== null && $this->projects->view($actor, $project);
    }

    public function create(User $actor): bool
    {
        return $this->projects->create($actor);
    }

    public function update(User $actor, Rab $rab): bool
    {
        $project = $rab->project;

        return $project !== null && $this->projects->update($actor, $project);
    }

    public function delete(User $actor, Rab $rab): bool
    {
        $project = $rab->project;

        return $project !== null && $this->projects->delete($actor, $project);
    }

    public function submit(User $actor, Rab $rab): bool
    {
        return $this->update($actor, $rab);
    }

    public function approve(User $actor, Rab $rab): bool
    {
        $project = $rab->project;

        return $project !== null
            && $rab->status === RabStatus::Submitted
            && $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $project->konsumen_id === (int) $actor->getKey();
    }
}
