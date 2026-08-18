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

    /**
     * Whether the actor may raise a payment charge (VA) for this installment
     * (consumer portal, Fase portal P-4). Ownership only — the payability STATE
     * guard (only an `unlocked` term may be charged; §7 keeps the bast/pelunasan
     * term locked until BAST is signed) lives solely in PaymentService::createCharge
     * and must not be duplicated here.
     */
    public function charge(User $actor, Installment $installment): bool
    {
        return $this->ownsProject($actor, $installment);
    }

    private function ownsProject(User $actor, Installment $installment): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $installment->project?->konsumen_id === (int) $actor->getKey();
    }
}
