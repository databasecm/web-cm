<?php

namespace App\Services;

use App\Enums\InstallmentStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Records the realisation of a consumer installment payment (Fase 3-4). Marking
 * a term paid and writing the Finance cash-book row are one atomic operation, so
 * a payment can never mark the term without an income entry (or vice versa).
 *
 * No gateway/VA yet (Fase 3-5, gateway abstraction) — this is the ledger side:
 * a payment is triggered manually/by a caller for now. Authorization (who may
 * record a payment) is the caller's job via the `recordPayment` gate.
 *
 * Guards:
 * - Only an UNLOCKED term is payable; a locked term (progress50 < 50%, or a
 *   pelunasan without a signed BAST) is rejected (CLAUDE.md §7).
 * - Idempotent + race-safe: the row is locked for update and a paid term is
 *   rejected, so a double submit never writes a duplicate income row.
 */
class PaymentService
{
    /**
     * Pay a single installment: mark it paid and post the income to the cash
     * book. Returns the created cash-book Transaction.
     */
    public function pay(Installment $installment, ?User $by = null): Transaction
    {
        return DB::transaction(function () use ($installment, $by): Transaction {
            // Lock the row so concurrent payments cannot both pass the guard.
            $locked = Installment::query()->whereKey($installment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === InstallmentStatus::Paid) {
                throw PaymentException::alreadyPaid();
            }

            if ($locked->status !== InstallmentStatus::Unlocked) {
                throw PaymentException::notPayable();
            }

            $amount = BigDecimal::of((string) $locked->amount)->toScale(2, RoundingMode::HALF_UP);

            $locked->update([
                'status' => InstallmentStatus::Paid,
                'paid_at' => now(),
            ]);

            return Transaction::create([
                'type' => TransactionType::Income,
                'category' => TransactionCategory::PembayaranKonsumen,
                'amount' => (string) $amount,
                'reference_type' => Transaction::REF_INSTALLMENT,
                'reference_id' => $locked->id,
                'description' => "Pembayaran termin {$locked->term_no} ({$locked->label}) proyek #{$locked->project_id}",
                'recorded_by' => $by?->id,
                'date' => now()->toDateString(),
            ]);
        });
    }
}
