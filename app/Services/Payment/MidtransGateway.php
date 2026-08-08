<?php

namespace App\Services\Payment;

use App\Models\Installment;
use RuntimeException;

/**
 * Real Midtrans gateway (Core API / VA) behind the PaymentGateway interface
 * (ADR-0012/0013). Selected only when PAYMENT_GATEWAY=midtrans in the production
 * environment; the credential-free SimulatedGateway remains the default.
 *
 * SCAFFOLD (Fase G-1): this class exists so the config-driven binding resolves.
 * The behaviour lands next:
 *   - G-2: verifyCallback() — real Midtrans SHA512 signature verification.
 *   - G-3: createCharge()   — Core API VA charge via the HTTP client.
 *
 * No third-party SDK is used (decision: HTTP-direct); no credential is read at
 * construction, so the binding resolves even before credentials are configured.
 */
class MidtransGateway implements PaymentGateway
{
    public function createCharge(Installment $installment): PaymentInstruction
    {
        throw new RuntimeException('MidtransGateway::createCharge belum diimplementasikan (Fase G-3).');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): PaymentSettlement
    {
        throw new RuntimeException('MidtransGateway::verifyCallback belum diimplementasikan (Fase G-2).');
    }
}
