<?php

namespace App\Services;

use App\Enums\BastParty;
use App\Enums\ProjectStatus;
use App\Exceptions\BastException;
use App\Models\Bast;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The BAST handover flow (Fase 3-2): issue the draft, record each party's
 * signature, and — once both have signed — advance to signed and open the
 * pelunasan installment.
 *
 * Unlock is delegated to {@see ProgressService::openBastInstallments()} so there
 * is a single control point for installment state (CLAUDE.md §7); this service
 * never flips installment status itself.
 */
class BastService
{
    public function __construct(private ProgressService $progress) {}

    /**
     * Issue the draft BAST for a project. One per project (1—1): re-issuing is
     * refused. The project must be active (it has a contract and a schedule).
     */
    public function issue(Project $project): Bast
    {
        if ($project->status !== ProjectStatus::Active) {
            throw BastException::projectNotActive();
        }

        if ($project->bast()->exists()) {
            throw BastException::alreadyIssued();
        }

        return Bast::create(['project_id' => $project->id]);
    }

    /**
     * Record one party's signature. Recording alone never advances the status
     * (3-1 invariant); only when BOTH parties have signed does the BAST become
     * signed and the pelunasan open. Idempotent: signing again, or after the
     * BAST is already signed, opens nothing further.
     */
    public function recordSignature(Bast $bast, BastParty $party): Bast
    {
        return DB::transaction(function () use ($bast, $party): Bast {
            match ($party) {
                BastParty::Customer => $bast->signed_customer = true,
                BastParty::Company => $bast->signed_company = true,
            };

            $bast->save();

            if ($bast->bothPartiesSigned() && ! $bast->isSigned()) {
                $bast->markSigned(); // status=signed, signed_at stamped (guarded)
                $this->progress->openBastInstallments($bast->project);
            }

            return $bast;
        });
    }
}
