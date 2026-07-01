<?php

namespace App\Policies;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for installment documents (Fase 3-7).
 *
 * A payment receipt (kuitansi) exists only for a PAID term and may be downloaded
 * by the owning consumer (their channel is the API) or by Finance / Owner /
 * Direktur (the cash side, via Filament).
 */
class InstallmentPolicy
{
    public function downloadReceipt(User $actor, Installment $installment): bool
    {
        if ($installment->status !== InstallmentStatus::Paid) {
            return false;
        }

        return $this->ownsProject($actor, $installment)
            || $actor->isFinance()
            || in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    private function ownsProject(User $actor, Installment $installment): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $installment->project?->konsumen_id === (int) $actor->getKey();
    }
}
