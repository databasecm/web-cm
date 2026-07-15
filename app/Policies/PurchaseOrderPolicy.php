<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for material purchase orders (Fase 6-5, SoD).
 *
 * - Finance + Owner/Direktur: full lifecycle — create, order, RECEIVE (the
 *   cash-out that posts the material expense), cancel.
 * - Manager (bidang-scoped): create + order POs for projects in its OWN bidang,
 *   and cancel them before receipt — but NEVER receive (receiving touches the
 *   cash book, which stays with Finance). Mirrors the payroll SoD (HR generates,
 *   Finance pays).
 * - Everyone else (HR, Mandor, Mitra, Konsumen): no access.
 *
 * Received/cancelled POs are final: no edit, no delete.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isFinance() || $actor->isManager();
    }

    public function view(User $actor, PurchaseOrder $po): bool
    {
        if ($this->isOverseer($actor) || $actor->isFinance()) {
            return true;
        }

        return $this->managerOwns($actor, $po);
    }

    public function create(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isFinance() || $actor->isManager();
    }

    /** Editable only while not final (draft/ordered), by Finance/O-D or the owning Manager. */
    public function update(User $actor, PurchaseOrder $po): bool
    {
        return ! $po->status->isFinal() && $this->manages($actor, $po);
    }

    public function delete(User $actor, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Draft && $this->manages($actor, $po);
    }

    /** Draft → ordered: Finance/O-D or the owning Manager. */
    public function order(User $actor, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Draft && $this->manages($actor, $po);
    }

    /** Ordered → received (posts the expense): Finance/O-D ONLY (SoD). */
    public function receive(User $actor, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Ordered
            && ($this->isOverseer($actor) || $actor->isFinance());
    }

    /** Cancel before receipt: Finance/O-D or the owning Manager. */
    public function cancel(User $actor, PurchaseOrder $po): bool
    {
        return ! $po->status->isFinal() && $this->manages($actor, $po);
    }

    /** Finance/O-D anywhere; a Manager only for its own bidang. */
    protected function manages(User $actor, PurchaseOrder $po): bool
    {
        return $this->isOverseer($actor) || $actor->isFinance() || $this->managerOwns($actor, $po);
    }

    protected function managerOwns(User $actor, PurchaseOrder $po): bool
    {
        return $actor->isManager()
            && $actor->bidang !== null
            && $po->project !== null
            && $po->project->bidang === $actor->bidang;
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }
}
