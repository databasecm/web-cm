<?php

namespace App\Services;

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Exceptions\InstallmentException;
use App\Models\Installment;
use App\Models\Project;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Project progress and the installment-unlock STATE machine (Fase 2B-6). No real
 * payment yet (Fase 3) — this only flips installment status.
 *
 * Unlock rules (konsep §5, CLAUDE.md §7):
 * - checkout   : unlocked at checkout (2B-5); untouched here.
 * - progress50 : locked → unlocked once project.progress_percent ≥ 50.
 * - bast       : the pelunasan opens ONLY after the project's BAST is signed
 *   (Fase 3-2, via openBastInstallments); any attempt before that is rejected
 *   here (CLAUDE.md §7).
 *
 * Idempotent: only locked terms transition, so crossing 50% repeatedly never
 * double-unlocks, and an already-unlocked term stays unlocked.
 */
class ProgressService
{
    /**
     * Set a project's progress (Manager of its bidang; Mandor arrives in Fase 5)
     * and open any progress-due installments that the new progress now satisfies.
     */
    public function setProgress(Project $project, string|float $percent, ?int $by = null): Project
    {
        $value = BigDecimal::of((string) $percent);

        if ($value->isLessThan('0') || $value->isGreaterThan('100')) {
            throw new InvalidArgumentException('Progres harus antara 0 dan 100.');
        }

        return DB::transaction(function () use ($project, $value): Project {
            // progress_percent change is audited via the project's Auditable trail.
            $project->forceFill(['progress_percent' => (string) $value->toScale(2, RoundingMode::HALF_UP)])->save();

            $this->openProgressInstallments($project);

            return $project;
        });
    }

    /**
     * Open every locked progress50 installment when progress has reached 50%.
     * Returns the number opened. Reuses the guarded unlock() so the bast guard
     * and idempotency hold in one place.
     */
    public function openProgressInstallments(Project $project): int
    {
        if (BigDecimal::of((string) $project->progress_percent)->isLessThan('50')) {
            return 0;
        }

        $opened = 0;

        $due = Installment::query()
            ->where('project_id', $project->id)
            ->where('due_condition', DueCondition::Progress50->value)
            ->where('status', InstallmentStatus::Locked->value)
            ->get();

        foreach ($due as $installment) {
            $installment->setRelation('project', $project);
            $this->unlock($installment);
            $opened++;
        }

        return $opened;
    }

    /**
     * Open every locked bast (pelunasan) installment once the project's BAST is
     * signed. Called by BastService when the BAST transitions to signed (Fase
     * 3-2). Returns the number opened. Reuses the guarded unlock(), so the §7
     * guard and idempotency hold in one place — a repeated call opens nothing
     * further because only locked terms transition.
     */
    public function openBastInstallments(Project $project): int
    {
        $opened = 0;

        $due = Installment::query()
            ->where('project_id', $project->id)
            ->where('due_condition', DueCondition::Bast->value)
            ->where('status', InstallmentStatus::Locked->value)
            ->get();

        foreach ($due as $installment) {
            $installment->setRelation('project', $project);
            $this->unlock($installment);
            $opened++;
        }

        return $opened;
    }

    /**
     * Unlock a single installment, enforcing the due-condition rules. Idempotent:
     * a non-locked term is returned unchanged. A bast term is rejected unless the
     * project's BAST is signed.
     */
    public function unlock(Installment $installment): Installment
    {
        if ($installment->status !== InstallmentStatus::Locked) {
            return $installment;
        }

        match ($installment->due_condition) {
            DueCondition::Bast => $this->assertBastSigned($installment),
            DueCondition::Progress50 => $this->assertProgressReached($installment),
            DueCondition::Checkout => null,
        };

        $installment->update(['status' => InstallmentStatus::Unlocked]);

        return $installment;
    }

    private function assertProgressReached(Installment $installment): void
    {
        if (BigDecimal::of((string) $installment->project->progress_percent)->isLessThan('50')) {
            throw InstallmentException::progressNotReached();
        }
    }

    /**
     * The pelunasan stays locked until the project's BAST is signed (CLAUDE.md
     * §7). No BAST, or an unsigned one, is rejected.
     */
    private function assertBastSigned(Installment $installment): void
    {
        $bast = $installment->project->bast;

        if ($bast === null || ! $bast->isSigned()) {
            throw InstallmentException::bastRequired();
        }
    }
}
