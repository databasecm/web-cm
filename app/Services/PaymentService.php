<?php

namespace App\Services;

use App\Enums\InstallmentStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentInstruction;
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
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Create a payment charge for an installment through the gateway and store
     * its reference on the term (Fase 3-5). Guards §7 exactly like pay(): only an
     * UNLOCKED term can be charged. Idempotent: a term that already has an open
     * charge returns that same instruction instead of creating a duplicate.
     */
    public function createCharge(Installment $installment): PaymentInstruction
    {
        if ($installment->status === InstallmentStatus::Paid) {
            throw PaymentException::alreadyPaid();
        }

        if ($installment->status !== InstallmentStatus::Unlocked) {
            throw PaymentException::notPayable();
        }

        // An active charge already exists → return it (no duplicate charge).
        if ($installment->gateway_ref !== null) {
            $amount = (string) BigDecimal::of((string) $installment->amount)->toScale(2, RoundingMode::HALF_UP);

            return new PaymentInstruction($installment->va_number, $installment->gateway_ref, $amount);
        }

        $instruction = $this->gateway->createCharge($installment);

        $installment->update([
            'va_number' => $instruction->vaNumber,
            'gateway_ref' => $instruction->gatewayRef,
        ]);

        return $instruction;
    }

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
