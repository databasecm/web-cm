<?php

namespace App\Services;

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Exceptions\CheckoutException;
use App\Models\Installment;
use App\Models\Project;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Checkout: choose a payment scheme and generate the installment schedule
 * (konsep §5, Fase 2B-5). No real payment / VA yet (Fase 3).
 *
 * Amounts are percentage × contract_value in BigDecimal; the last term takes the
 * rounding remainder so Σ(amounts) == contract_value EXACTLY (no lost cents). The
 * checkout term unlocks immediately; progress50 and bast terms start locked.
 */
class CheckoutService
{
    public function checkout(Project $project, PaymentScheme $scheme): Project
    {
        if ($project->contract_value === null) {
            throw CheckoutException::noContractValue();
        }

        if ($project->installments()->exists()) {
            throw CheckoutException::alreadyCheckedOut();
        }

        return DB::transaction(function () use ($project, $scheme): Project {
            $this->generateInstallments($project, $scheme);

            $project->forceFill([
                'payment_scheme' => $scheme,
                'status' => ProjectStatus::Active,
            ])->save();

            return $project->refresh();
        });
    }

    private function generateInstallments(Project $project, PaymentScheme $scheme): void
    {
        $total = BigDecimal::of((string) $project->contract_value);
        $terms = $scheme->terms();
        $lastIndex = count($terms) - 1;
        $allocated = BigDecimal::zero();

        foreach ($terms as $index => $term) {
            if ($index === $lastIndex) {
                // The final term absorbs the rounding remainder so the schedule
                // sums to the contract value exactly.
                $amount = $total->minus($allocated);
            } else {
                $amount = $total
                    ->multipliedBy(BigDecimal::of($term['percentage'])->dividedBy('100', 10, RoundingMode::HALF_UP))
                    ->toScale(2, RoundingMode::HALF_UP);
                $allocated = $allocated->plus($amount);
            }

            Installment::create([
                'project_id' => $project->id,
                'term_no' => $index + 1,
                'label' => $term['label'],
                'percentage' => $term['percentage'],
                'amount' => (string) $amount->toScale(2, RoundingMode::HALF_UP),
                'due_condition' => $term['due_condition'],
                'status' => $term['due_condition'] === DueCondition::Checkout
                    ? InstallmentStatus::Unlocked
                    : InstallmentStatus::Locked,
            ]);
        }
    }
}
