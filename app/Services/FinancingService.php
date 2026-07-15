<?php

namespace App\Services;

use App\Enums\FinancingStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\FinancingException;
use App\Models\Financing;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The financing application flow (Fase 4-2). Pure business logic + guards;
 * authorization is the caller's job via the applyFinancing gate and the
 * FinancingPolicy::manageLifecycle ability (like PaymentService + recordPayment).
 *
 * Scope separation (§6.5): every action here only ever touches financings,
 * financing_status_logs and the income side of the cash book — never the
 * projects table.
 */
class FinancingService
{
    /**
     * A consumer applies to a bank partner to finance a project. Creates a
     * submitted application; the model enforces one active financing per project.
     */
    public function apply(Project $project, User $konsumen, User $bank, string|float $amount): Financing
    {
        return Financing::create([
            'project_id' => $project->id,
            'konsumen_id' => $konsumen->id,
            'bank_mitra_id' => $bank->id,
            'amount' => (string) BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HALF_UP),
            'status' => FinancingStatus::Submitted,
        ]);
    }

    /**
     * Move the application along its lifecycle (submitted → docs_required →
     * interview → approved/rejected). Delegates to Financing::transitionTo() so
     * the legal-transition guard and the status-log trail (Fase 4-1) hold.
     * Disbursement is a separate, guarded step (see disburse()).
     */
    public function transition(Financing $financing, FinancingStatus $newStatus, ?User $by = null, ?string $note = null): Financing
    {
        if ($newStatus === FinancingStatus::Disbursed) {
            // Disbursement posts to the cash book — force it through disburse().
            throw FinancingException::notApproved();
        }

        return $financing->transitionTo($newStatus, $by?->id, $note);
    }

    /**
     * Disburse an approved financing: approved → disbursed AND post the income to
     * the cash book under the INVESTOR category (keputusan D) so the funding
     * source is unambiguous. Idempotent + race-safe: the row is locked and an
     * already-disbursed financing is refused, so a double call never posts a
     * duplicate income row.
     */
    public function disburse(Financing $financing, ?User $by = null): Transaction
    {
        return DB::transaction(function () use ($financing, $by): Transaction {
            // Lock the row across every actor (ignore the bank scope) so concurrent
            // disbursements cannot both pass the guard.
            $locked = Financing::withoutGlobalScopes()
                ->whereKey($financing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === FinancingStatus::Disbursed) {
                throw FinancingException::alreadyDisbursed();
            }

            if ($locked->status !== FinancingStatus::Approved) {
                throw FinancingException::notApproved();
            }

            $locked->transitionTo(FinancingStatus::Disbursed, $by?->id, 'Pencairan dana pembiayaan');

            $amount = (string) BigDecimal::of((string) $locked->amount)->toScale(2, RoundingMode::HALF_UP);

            return Transaction::create([
                'type' => TransactionType::Income,
                'category' => TransactionCategory::Investor,
                'amount' => $amount,
                'reference_type' => Transaction::REF_FINANCING,
                'reference_id' => $locked->id,
                'project_id' => $locked->project_id, // per-project P&L (Fase 6-3b)
                'description' => "Pencairan pembiayaan proyek #{$locked->project_id}",
                'recorded_by' => $by?->id,
                'date' => now()->toDateString(),
            ]);
        });
    }
}
