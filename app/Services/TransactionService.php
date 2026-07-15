<?php

namespace App\Services;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\TransactionException;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Records manual cash-book entries (Fase 6-3) — the only rows Finance writes by
 * hand. Automatic money movements (consumer installments, financing
 * disbursements, payroll) are posted by their own services and must never be
 * duplicated here; this service refuses any auto-sourced category so the cash
 * book can't double-count (see {@see TransactionCategory::manualOptions()}).
 *
 * Pure service + guard; authorization is the caller's job (TransactionPolicy).
 * Every row is Auditable (§6.6).
 */
class TransactionService
{
    /**
     * Post a hand-entered income/expense row and tag it manual (so it stays
     * editable/deletable while auto rows do not).
     */
    public function recordManual(
        TransactionType $type,
        TransactionCategory $category,
        string $amount,
        string $date,
        ?string $description = null,
        ?User $by = null,
        ?int $projectId = null,
    ): Transaction {
        if (! $category->isManualFor($type)) {
            throw TransactionException::categoryNotManual($category, $type);
        }

        $normalized = (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HALF_UP);

        return Transaction::create([
            'type' => $type,
            'category' => $category,
            'amount' => $normalized,
            'reference_type' => Transaction::REF_MANUAL,
            'reference_id' => null,
            // Optional per-project link (Fase 6-3b) so a manual expense can feed
            // project P&L; left null when the entry is general overhead.
            'project_id' => $projectId,
            'description' => $description,
            'recorded_by' => $by?->id,
            'date' => $date,
        ]);
    }
}
