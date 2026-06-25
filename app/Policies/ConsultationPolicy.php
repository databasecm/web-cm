<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for persisted consultation threads (CLAUDE.md §6.4, ADR-0003).
 *
 * Two sides participate:
 * - The consumer (level 6) who owns the thread (`konsumen_id`).
 * - Staff who triage it: Owner (1) and Direktur (2) see everything; a Manager
 *   (3) only reaches threads in its own `bidang`. Finance/HR (also level 3),
 *   Mitra (4) and Mandor (5) are not part of consultations at all.
 *
 * Guest (no-login) sessions never reach this policy — they live only in Redis
 * and are governed by their own stateless endpoints (ADR-0003).
 */
class ConsultationPolicy
{
    /**
     * Staff who may triage consultations in general (capability check, no
     * specific thread): Owner, Direktur, or a Manager.
     */
    protected function isStaff(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true)
            || $actor->isManager();
    }

    /**
     * Whether a staff actor's reach covers this particular thread. Owner and
     * Direktur span every bidang; a Manager is confined to its own (§6.4).
     */
    protected function staffHandles(User $actor, Consultation $consultation): bool
    {
        if (in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true)) {
            return true;
        }

        return $actor->isManager() && $actor->bidang === $consultation->bidang;
    }

    /**
     * Whether the actor is the consumer who owns this thread.
     */
    protected function owns(User $actor, Consultation $consultation): bool
    {
        return $consultation->konsumen_id !== null
            && (int) $consultation->konsumen_id === (int) $actor->getKey();
    }

    /**
     * Who may browse a consultation list: triage staff, or a consumer viewing
     * their own threads (the query itself scopes a consumer to its own rows).
     */
    public function viewAny(User $actor): bool
    {
        return $this->isStaff($actor) || $actor->level() === Role::LEVEL_KONSUMEN;
    }

    /**
     * Who may view a specific thread: its owner, or staff whose reach covers it.
     */
    public function view(User $actor, Consultation $consultation): bool
    {
        return $this->owns($actor, $consultation)
            || ($this->isStaff($actor) && $this->staffHandles($actor, $consultation));
    }

    /**
     * Who may open a consultation: a consumer (their own) or triage staff
     * (on behalf / via the deal-promotion path). Field-level constraints
     * (konsumen_id, bidang) are enforced by the Form Request layer.
     */
    public function create(User $actor): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN || $this->isStaff($actor);
    }

    /**
     * Who may mutate the thread record itself — claim it, change its status,
     * close it: triage staff whose reach covers it. The owning consumer drives
     * the conversation through messages, not by editing the thread record.
     */
    public function update(User $actor, Consultation $consultation): bool
    {
        return $this->isStaff($actor) && $this->staffHandles($actor, $consultation);
    }

    /**
     * Who may post a message into the thread: the owning consumer or covering
     * staff, and only while the thread is not closed.
     */
    public function respond(User $actor, Consultation $consultation): bool
    {
        if ($consultation->isClosed()) {
            return false;
        }

        return $this->owns($actor, $consultation)
            || ($this->isStaff($actor) && $this->staffHandles($actor, $consultation));
    }

    /**
     * Who may delete a thread: only Owner and Direktur. Threads are normally
     * retired by closing (status), not deletion.
     */
    public function delete(User $actor, Consultation $consultation): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }
}
