<?php

namespace App\Services\Payment;

use App\Models\Installment;

/**
 * Contract for a payment gateway (Fase 3-5). The default binding is the
 * {@see SimulatedGateway}; a real Midtrans/Xendit implementation later slots in
 * behind this same interface without touching the payment flow (ADR-0012).
 */
interface PaymentGateway
{
    /**
     * Create a charge for a payable installment and return the pay instruction
     * (VA/reference + amount + pending status). Implementations must be free of
     * business-state guards — PaymentService enforces §7 before calling this.
     */
    public function createCharge(Installment $installment): PaymentInstruction;

    /**
     * Verify a raw gateway callback payload and return the settlement (charge
     * reference + paid flag). Used by the Fase 3-6 webhook; a real gateway
     * verifies the signature here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): PaymentSettlement;
}
