<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentException;
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
    /**
     * Stand-in signing key for the simulation. NOT a real secret — a real
     * gateway verifies with a credential from config/env, never a hard-coded key.
     */
    private const SIM_SECRET = 'simulated-gateway-signing-key';

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
     * Verify the callback signature (deterministic HMAC over the reference) and
     * build the settlement. A missing reference or a bad signature is rejected —
     * a real gateway verifies against its own credential here instead.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): PaymentSettlement
    {
        $ref = (string) ($payload['gateway_ref'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');

        if ($ref === '' || ! hash_equals($this->sign($ref), $signature)) {
            throw PaymentException::invalidCallback();
        }

        return new PaymentSettlement(
            gatewayRef: $ref,
            paid: ($payload['status'] ?? null) === 'paid',
        );
    }

    /**
     * Deterministic signature for a charge reference. Public so dev/tests can
     * build a valid callback payload without any network call.
     */
    public function sign(string $gatewayRef): string
    {
        return hash_hmac('sha256', $gatewayRef, self::SIM_SECRET);
    }

    /**
     * Build a signed callback payload for an installment's charge — the shape a
     * real gateway would POST to the webhook. Deterministic, no I/O.
     *
     * @return array<string, string>
     */
    public function callbackPayload(Installment $installment, string $status = 'paid'): array
    {
        $ref = $this->refFor($installment);

        return [
            'gateway_ref' => $ref,
            'status' => $status,
            'signature' => $this->sign($ref),
        ];
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
