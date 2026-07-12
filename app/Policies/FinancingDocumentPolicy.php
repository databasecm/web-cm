<?php

namespace App\Policies;

use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for financing documents (Fase 4-3).
 *
 * These files are sensitive (KTP, payslips), so visibility is tight: only the
 * owning consumer, the owning bank, and Owner/Direktur — NOT Managers or Finance.
 * Review (accept/reject/request-more) is the owning bank's write action, or an
 * overseer's. Consumers never review their own documents. As with the rest of
 * financing, none of this touches project data (§6.5).
 */
class FinancingDocumentPolicy
{
    public function view(User $actor, FinancingDocument $document): bool
    {
        $financing = $document->financing;

        return $financing !== null
            && ($this->isOverseer($actor)
                || $this->ownsFinancing($actor, $financing)
                || $this->banksFinancing($actor, $financing));
    }

    /**
     * Upload a document for a financing — the owning consumer only. Exposed via
     * the uploadFinancingDocument gate (no document instance yet).
     */
    public function upload(User $actor, Financing $financing): bool
    {
        return $this->ownsFinancing($actor, $financing);
    }

    /**
     * Review (accept/reject/request more) — the owning bank or an overseer.
     */
    public function review(User $actor, FinancingDocument $document): bool
    {
        $financing = $document->financing;

        return $financing !== null
            && ($this->isOverseer($actor) || $this->banksFinancing($actor, $financing));
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    protected function ownsFinancing(User $actor, Financing $financing): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $financing->konsumen_id === (int) $actor->getKey();
    }

    protected function banksFinancing(User $actor, Financing $financing): bool
    {
        return $actor->isBankMitra()
            && $financing->bank_mitra_id !== null
            && (int) $financing->bank_mitra_id === (int) $actor->getKey();
    }
}
