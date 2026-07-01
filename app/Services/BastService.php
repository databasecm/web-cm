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
    public function issue(Project $project, ?string $file = null): Bast
    {
        if ($project->status !== ProjectStatus::Active) {
            throw BastException::projectNotActive();
        }

        if ($project->bast()->exists()) {
            throw BastException::alreadyIssued();
        }

        return Bast::create(['project_id' => $project->id, 'file' => $file]);
    }

    /**
     * Attach/replace the BAST document reference (path/link for now — binary
     * upload to object storage is deferred to the media phase, like designs).
     */
    public function setFile(Bast $bast, ?string $file): Bast
    {
        $bast->update(['file' => $file]);

        return $bast;
    }

    /**
     * Record one party's signature and who signed it. Recording alone never
     * advances the status (3-1 invariant); only when BOTH parties have signed
     * does the BAST become signed and the pelunasan open. Idempotent: signing
     * again, or after the BAST is already signed, opens nothing further.
     */
    public function recordSignature(Bast $bast, BastParty $party, ?int $by = null): Bast
    {
        return DB::transaction(function () use ($bast, $party, $by): Bast {
            match ($party) {
                BastParty::Customer => $bast->forceFill(['signed_customer' => true, 'signed_customer_by' => $by]),
                BastParty::Company => $bast->forceFill(['signed_company' => true, 'signed_company_by' => $by]),
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
