<?php

namespace App\Notifications;

use App\Enums\Bidang;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place that answers "who is told about this event?" (Fase 7).
 *
 * Triggers never build their own recipient lists — they ask here — so the
 * event→recipient map can never drift out of sync with §6. Two hard rules:
 *
 *   1. FAIL CLOSED. An event with no registered rule notifies no one. Silence
 *      never leaks across a boundary; over-notifying can. When a new event
 *      needs a recipient rule that no existing policy expresses, that rule is
 *      raised to the owner — never invented here.
 *   2. Recipients are always derived from EXISTING relations/policies. Prefer
 *      running candidates through the very policy ability that guards the
 *      detail page (`authorizedTo`), so who gets the knock can never out-run
 *      who may open the door.
 *
 * This is the 7-1 skeleton: the per-event rules land with their triggers
 * (7-3..7-6). The reusable primitives below are already policy/relation-backed.
 */
class RecipientResolver
{
    /**
     * Recipients for a business event about `$entity`. Fail-closed: an event
     * with no rule, or one whose subject has no project, resolves to nobody.
     *
     * Every rule below mirrors an EXISTING policy/relation — never a new one:
     *   - project owner            = Project::konsumen (ProjectPolicy::owns)
     *   - Finance + overseers      = PaymentPolicy::record (who books a payment)
     *   - Manager of the bidang    = ProjectPolicy manager clause (isManager +
     *                                bidang === project bidang)
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(string $event, Model $entity): Collection
    {
        // Payroll events go to the internal payroll audience only — the very set
        // PayrollPolicy::viewAny authorises (HR + Finance + overseers). Managers
        // are never included, and workers are Employee records, not accounts, so
        // they can never be recipients (§7). Salary figures live in the
        // PayrollResource behind the action_url, never in a notification.
        if (in_array($event, ['payroll.generated', 'payroll.paid'], true)) {
            return $this->payrollAudience();
        }

        // Financing events resolve off the financing's OWN parties (§6.5): the
        // owning consumer and the owning bank (bank_mitra_id) — never a project's
        // wider audience, and NEVER another bank. Handled before the project
        // branch because a Financing also has a project.
        $financing = $this->financingOf($entity);

        if ($financing !== null) {
            /** @var Collection<int, User> $recipients */
            $recipients = match ($event) {
                // E6 — lifecycle transition: the applicant and its bank.
                'financing.status_changed' => $this->financingOwner($financing)
                    ->merge($this->financingBank($financing)),

                // E7 — disbursed (cash out): same, plus the cash overseers.
                'financing.disbursed' => $this->financingOwner($financing)
                    ->merge($this->financingBank($financing))
                    ->merge($this->finance())
                    ->merge($this->overseers()),

                // E8 — document reviewed: ONLY the owning consumer. The bank did
                // the review; Manager/Finance never see financing documents
                // (FinancingDocumentPolicy, 4-3).
                'financing_document.reviewed' => $this->financingOwner($financing),

                default => $this->none(),
            };

            return $recipients->unique('id')->values();
        }

        $project = $this->projectOf($entity);

        if ($project === null) {
            return $this->none();
        }

        /** @var Collection<int, User> $recipients */
        $recipients = match ($event) {
            // E1 — a term/pelunasan was paid: the paying consumer plus whoever
            // may book it in the cash book (Finance + Owner/Direktur).
            'payment.paid' => $this->owner($project)
                ->merge($this->finance())
                ->merge($this->overseers()),

            // E2 — BAST issued: the consumer who will sign, and the bidang's
            // Manager who runs the handover.
            'bast.issued' => $this->owner($project)
                ->merge($this->managersOfBidang($project->bidang)),

            // E3 — BAST signed: same, plus Finance (the pelunasan term is now
            // open to be paid).
            'bast.signed' => $this->owner($project)
                ->merge($this->managersOfBidang($project->bidang))
                ->merge($this->finance()),

            // E4 progress, E5 daily report, E12 RAB finalised — the owning
            // consumer and the bidang's Manager (ProjectPolicy view/manage).
            // Finance is deliberately NOT on RAB finalise: their cash concern is
            // the payment, not the contract (RabPolicy has no Finance role).
            'progress.updated', 'daily_report.created', 'rab.finalized' => $this->owner($project)
                ->merge($this->managersOfBidang($project->bidang)),

            // E11 — PO received (a cash-out): the bidang's Manager (who raised it)
            // and Finance (the cash book). NOT the consumer — this is internal.
            'po.received' => $this->managersOfBidang($project->bidang)
                ->merge($this->finance()),

            default => $this->none(),
        };

        return $recipients->unique('id')->values();
    }

    /**
     * Owner + Direktur — the always-authorized overseers (§6.3). Reused by many
     * events; kept here so the "who oversees everything" set is defined once.
     *
     * @return Collection<int, User>
     */
    public function overseers(): Collection
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('level', [
                Role::LEVEL_OWNER,
                Role::LEVEL_DIREKTUR,
            ]))
            ->get();
    }

    /**
     * Keep only candidates a policy actually authorizes for `$ability` on
     * `$entity`. Recipient selection reuses the detail page's gate, so it can
     * never widen access beyond §6 — the core Fase 7 privacy guarantee.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function authorizedTo(string $ability, Collection $candidates, Model $entity): Collection
    {
        return $candidates
            ->filter(fn (User $user) => $user->can($ability, $entity))
            ->values();
    }

    /**
     * The owning consumer of a project (ProjectPolicy::owns), as a 0-or-1 set.
     *
     * @return Collection<int, User>
     */
    private function owner(Project $project): Collection
    {
        return User::query()->whereKey($project->konsumen_id)->get();
    }

    /**
     * All Finance accounts (PaymentPolicy::record — the cash-book recorders).
     *
     * @return Collection<int, User>
     */
    private function finance(): Collection
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->where('name', Role::NAME_FINANCE))
            ->get();
    }

    /** All HR accounts. @return Collection<int, User> */
    private function hr(): Collection
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->where('name', Role::NAME_HR))
            ->get();
    }

    /**
     * Everyone allowed to see payroll (PayrollPolicy::viewAny): HR + Finance +
     * overseers. Managers and workers are deliberately excluded.
     *
     * @return Collection<int, User>
     */
    private function payrollAudience(): Collection
    {
        return $this->hr()
            ->merge($this->finance())
            ->merge($this->overseers())
            ->unique('id')
            ->values();
    }

    /**
     * Managers whose bidang matches the project's (the ProjectPolicy manager
     * clause). Overseers are intentionally NOT swept in here — the BAST map
     * addresses the bidang's Manager, not every overseer.
     *
     * @return Collection<int, User>
     */
    private function managersOfBidang(?Bidang $bidang): Collection
    {
        if ($bidang === null) {
            return $this->none();
        }

        return User::query()
            ->where('bidang', $bidang)
            ->whereHas('role', fn ($query) => $query->where('name', Role::NAME_MANAGER))
            ->get();
    }

    /**
     * The owning consumer of a financing (Financing::konsumen), as a 0-or-1 set.
     *
     * @return Collection<int, User>
     */
    private function financingOwner(Financing $financing): Collection
    {
        return User::query()->whereKey($financing->konsumen_id)->get();
    }

    /**
     * The financing's OWN bank (bank_mitra_id), as a 0-or-1 set. Any other bank
     * resolves to nothing here — that is the §6.5 boundary made mechanical.
     *
     * @return Collection<int, User>
     */
    private function financingBank(Financing $financing): Collection
    {
        return User::query()->whereKey($financing->bank_mitra_id)->get();
    }

    /** The financing a subject hangs off (itself, or a document's parent), or null. */
    private function financingOf(Model $entity): ?Financing
    {
        if ($entity instanceof Financing) {
            return $entity;
        }

        if ($entity instanceof FinancingDocument) {
            return $entity->financing;
        }

        return null;
    }

    /** The project a business subject hangs off, or null. */
    private function projectOf(Model $entity): ?Project
    {
        if ($entity instanceof Project) {
            return $entity;
        }

        return method_exists($entity, 'project') ? $entity->project : null;
    }

    /** @return Collection<int, User> */
    private function none(): Collection
    {
        return User::query()->whereRaw('1 = 0')->get();
    }
}
