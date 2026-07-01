<?php

namespace App\Services\Payment;

/**
 * The verified outcome of a gateway callback (used by the Fase 3-6 webhook): the
 * charge reference and whether it was actually paid. A real gateway builds this
 * only after verifying the callback signature; the simulated gateway trusts the
 * payload. Immutable value object — no I/O.
 */
final class PaymentSettlement
{
    public function __construct(
        public readonly string $gatewayRef,
        public readonly bool $paid,
    ) {}
}
