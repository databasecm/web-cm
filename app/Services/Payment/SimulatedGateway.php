<?php

namespace App\Services\Payment;

use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Default, credential-free gateway (Fase 3-5, ADR-0012). It produces a
 * deterministic dummy VA/reference from the installment id and makes no network
 * call, so dev and tests run without any external sandbox.
 *
 * Its simulatePaymentReceived() helper drives the existing settlement path
 * (PaymentService::pay, Fase 3-4) to mimic a customer paying — the same path a
 * real gateway's callback will take via the Fase 3-6 webhook.
 */
class SimulatedGateway implements PaymentGateway
{
    public function createCharge(Installment $installment): PaymentInstruction
    {
        $amount = BigDecimal::of((string) $installment->amount)->toScale(2, RoundingMode::HALF_UP);

        return new PaymentInstruction(
            vaNumber: $this->vaFor($installment),
            gatewayRef: $this->refFor($installment),
            amount: (string) $amount,
        );
    }

    /**
     * The simulation trusts the payload (no signature to verify). A real gateway
     * verifies the callback signature before building the settlement.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): PaymentSettlement
    {
        return new PaymentSettlement(
            gatewayRef: (string) ($payload['gateway_ref'] ?? ''),
            paid: ($payload['status'] ?? null) === 'paid',
        );
    }

    /**
     * Dev/test helper: simulate the customer paying this charge, settling it
     * through the real ledger path (PaymentService::pay). Resolved lazily to
     * avoid a construction-time dependency cycle (PaymentService depends on the
     * gateway).
     */
    public function simulatePaymentReceived(Installment $installment, ?User $by = null): Transaction
    {
        return app(PaymentService::class)->pay($installment, $by);
    }

    private function refFor(Installment $installment): string
    {
        return 'SIM-CHG-'.str_pad((string) $installment->id, 8, '0', STR_PAD_LEFT);
    }

    private function vaFor(Installment $installment): string
    {
        return '8808'.str_pad((string) $installment->id, 10, '0', STR_PAD_LEFT);
    }
}
