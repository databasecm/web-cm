<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;

/**
 * Authorization for the Finance cash book (Fase 6-3, CLAUDE.md §6.6).
 *
 * The cash book is sensitive financial data: only Finance and overseers
 * (Owner/Direktur) may see or write it. HR, Manager, Mandor, Mitra and Konsumen
 * get NOTHING — no resource, no rows.
 *
 * Auto-sourced rows (installments, financing disbursements, payroll — and PO in
 * Fase 6-5) mirror real events and are immutable in the book; only a manual row
 * may be edited or deleted, by its recorder or an overseer.
 */
class TransactionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canAccess($actor);
    }

    public function view(User $actor, Transaction $transaction): bool
    {
        return $this->canAccess($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canAccess($actor);
    }

    /**
     * Edit is confined to manual rows and to the recorder or an overseer.
     * Auto-sourced rows can never be altered from the cash book.
     */
    public function update(User $actor, Transaction $transaction): bool
    {
        return $this->canAccess($actor)
            && $transaction->isManual()
            && ($transaction->recorded_by === $actor->id || $this->isOverseer($actor));
    }

    public function delete(User $actor, Transaction $transaction): bool
    {
        return $this->update($actor, $transaction);
    }

    /** Finance keeps the book; overseers see everything. Nobody else. */
    protected function canAccess(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isFinance();
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }
}
