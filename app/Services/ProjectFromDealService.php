<?php

namespace App\Services;

use App\Enums\ConsultationStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ProjectConversionException;
use App\Models\Consultation;
use App\Models\Project;
use App\Models\User;

/**
 * Creates a draft project from a consultation deal (Fase 2B bridge).
 *
 * A deal-bound forward-creation: it only ever produces ONE draft project per
 * deal, deriving bidang/konsumen/manager from the consultation. It is authorized
 * by the narrow `createProjectForDeal` gate (never by widening ProjectPolicy or
 * the account hierarchy) and enforces the state invariants here so the rule
 * holds even if called directly. Project creation is audited via the Auditable
 * trail.
 */
class ProjectFromDealService
{
    /**
     * @param  string|null  $title  optional project title; defaults from the deal
     */
    public function create(Consultation $consultation, User $actor, ?string $title = null): Project
    {
        if ($consultation->status !== ConsultationStatus::Deal) {
            throw ProjectConversionException::notADeal();
        }

        if ($consultation->konsumen_id === null) {
            throw ProjectConversionException::noCustomerAccount();
        }

        if (Project::query()->where('consultation_id', $consultation->id)->exists()) {
            throw ProjectConversionException::alreadyHasProject();
        }

        return Project::create([
            'konsumen_id' => $consultation->konsumen_id,
            'consultation_id' => $consultation->id,
            // Carry over the claiming Manager; if unclaimed and the actor is a
            // Manager, they take it on.
            'manager_id' => $consultation->manager_id ?? ($actor->isManager() ? $actor->id : null),
            'bidang' => $consultation->bidang,
            'title' => $title !== null && trim($title) !== ''
                ? trim($title)
                : 'Proyek '.($consultation->konsumen?->name ?? 'Konsumen'),
            'status' => ProjectStatus::Draft,
        ]);
    }
}
