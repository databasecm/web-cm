<?php

namespace App\Services\Payment;

/**
 * The pay instruction a gateway returns for a charge (Fase 3-5): a virtual
 * account / reference the consumer pays to, the amount, and the charge status
 * (pending until a callback settles it). Immutable value object — no I/O.
 */
final class PaymentInstruction
{
    public function __construct(
        public readonly string $vaNumber,
        public readonly string $gatewayRef,
        public readonly string $amount,   // fixed 2-decimal string (BigDecimal)
        public readonly string $status = 'pending',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'va_number' => $this->vaNumber,
            'gateway_ref' => $this->gatewayRef,
            'amount' => $this->amount,
            'status' => $this->status,
        ];
    }
}
