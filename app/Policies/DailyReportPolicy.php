<?php

namespace App\Policies;

use App\Models\DailyReport;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for daily field reports (Fase 5-3).
 *
 * - Write (create/update): a Mandor in the project's bidang, or Owner/Direktur.
 * - View: the writing Mandor / a Manager (its bidang), Owner/Direktur, the owning
 *   consumer (auto-linked to their project page, konsep §6), and the financing
 *   bank of the project (read-only, §6.5).
 * - HR / Finance / Supplier: no access by default.
 */
class DailyReportPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor)
            || $actor->isManager()
            || $actor->isMandor()
            || $actor->level() === Role::LEVEL_KONSUMEN
            || $actor->isBankMitra();
    }

    public function view(User $actor, DailyReport $report): bool
    {
        $project = $report->project;
        if ($project === null) {
            return false;
        }

        if ($this->isOverseer($actor)) {
            return true;
        }

        if ($actor->isManager() || $actor->isMandor()) {
            return $actor->bidang !== null && $actor->bidang === $project->bidang;
        }

        // Owning consumer (auto-linked) or the project's financing bank (read-only).
        return $this->ownsProject($actor, $project) || $this->banksProject($actor, $project);
    }

    /**
     * File a report on a project — a Mandor in its bidang, or an overseer.
     * Exposed via the createDailyReport gate (no report instance yet).
     */
    public function create(User $actor, Project $project): bool
    {
        return $this->isOverseer($actor)
            || ($actor->isMandor() && $actor->bidang !== null && $actor->bidang === $project->bidang);
    }

    public function update(User $actor, DailyReport $report): bool
    {
        $project = $report->project;

        return $project !== null && $this->create($actor, $project);
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    protected function ownsProject(User $actor, Project $project): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $project->konsumen_id === (int) $actor->getKey();
    }

    protected function banksProject(User $actor, Project $project): bool
    {
        return $actor->isBankMitra()
            && $project->bank_mitra_id !== null
            && (int) $project->bank_mitra_id === (int) $actor->getKey();
    }
}
