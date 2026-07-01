<?php

namespace App\Jobs;

use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Settles a verified payment callback off the request cycle (Fase 3-6). The
 * webhook verifies the signature synchronously and returns 200 fast; the actual
 * ledger write happens here.
 *
 * Idempotent + §7-safe by delegating to {@see PaymentService::pay()}: a term that
 * is already paid (replayed callback) or still locked (e.g. a pelunasan without a
 * signed BAST) raises a PaymentException which is caught and turned into a no-op,
 * so a double callback never double-pays and a locked term is never settled.
 */
class ProcessPaymentCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $gatewayRef,
        public readonly bool $paid,
    ) {}

    public function handle(PaymentService $payments): void
    {
        if (! $this->paid) {
            return; // pending/expired callback — nothing to settle.
        }

        $installment = Installment::query()->where('gateway_ref', $this->gatewayRef)->first();

        if ($installment === null) {
            Log::warning('payment.webhook.unknown_ref', ['gateway_ref' => $this->gatewayRef]);

            return;
        }

        try {
            $payments->pay($installment);
            Log::info('payment.webhook.settled', [
                'gateway_ref' => $this->gatewayRef,
                'installment_id' => $installment->id,
            ]);
        } catch (PaymentException $e) {
            // Already paid (idempotent replay) or locked (§7): no state change.
            Log::info('payment.webhook.noop', [
                'gateway_ref' => $this->gatewayRef,
                'installment_id' => $installment->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
