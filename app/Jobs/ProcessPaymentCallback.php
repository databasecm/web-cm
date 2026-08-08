<?php

namespace App\Jobs;

use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\PaymentWebhookLog;
use App\Services\Payment\PaymentWebhookLogger;
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
 *
 * Writes the FINAL audit outcome for a verified callback (settled/noop/
 * unknown_ref, ADR-0013) — the controller already logged any rejected one.
 */
class ProcessPaymentCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  redacted callback payload (no signature material)
     */
    public function __construct(
        public readonly string $gatewayRef,
        public readonly bool $paid,
        public readonly ?string $transactionStatus = null,
        public readonly array $payload = [],
    ) {}

    public function handle(PaymentService $payments, PaymentWebhookLogger $logger): void
    {
        $audit = fn (string $outcome) => $logger->record([
            'gateway' => (string) config('payment.gateway'),
            'gateway_ref' => $this->gatewayRef,
            'transaction_status' => $this->transactionStatus,
            'verified' => true, // only verified callbacks reach the job
            'outcome' => $outcome,
            'ip' => null, // async — no request context
            'payload' => $this->payload,
        ]);

        if (! $this->paid) {
            $audit(PaymentWebhookLog::OUTCOME_NOOP); // pending/expired — nothing to settle

            return;
        }

        $installment = Installment::query()->where('gateway_ref', $this->gatewayRef)->first();

        if ($installment === null) {
            Log::warning('payment.webhook.unknown_ref', ['gateway_ref' => $this->gatewayRef]);
            $audit(PaymentWebhookLog::OUTCOME_UNKNOWN_REF);

            return;
        }

        try {
            $payments->pay($installment);
            Log::info('payment.webhook.settled', [
                'gateway_ref' => $this->gatewayRef,
                'installment_id' => $installment->id,
            ]);
            $audit(PaymentWebhookLog::OUTCOME_SETTLED);
        } catch (PaymentException $e) {
            // Already paid (idempotent replay) or locked (§7): no state change.
            Log::info('payment.webhook.noop', [
                'gateway_ref' => $this->gatewayRef,
                'installment_id' => $installment->id,
                'reason' => $e->getMessage(),
            ]);
            $audit(PaymentWebhookLog::OUTCOME_NOOP);
        }
    }
}
