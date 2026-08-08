<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentException;
use App\Models\Installment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;

/**
 * Real Midtrans gateway (Core API / VA) behind the PaymentGateway interface
 * (ADR-0012/0013). Selected only when PAYMENT_GATEWAY=midtrans in the production
 * environment; the credential-free SimulatedGateway remains the default. No
 * third-party SDK — HTTP-direct (decision G-1).
 *
 * G-2 implements verifyCallback(): the gate between a real payment and a forged
 * one. G-3 implements createCharge(): a Core API bank_transfer VA charge.
 *
 * This class is intentionally free of business-state guards: PaymentService
 * enforces §7 (only an UNLOCKED term is charged) and the "one charge per term"
 * idempotency (a term that already has a gateway_ref returns the same
 * instruction) BEFORE calling here (Fase 3-5). The gateway only talks to
 * Midtrans and maps the response.
 */
class MidtransGateway implements PaymentGateway
{
    /** Transaction statuses that can mean "paid" (subject to the fraud check). */
    private const PAID_STATUSES = ['settlement', 'capture'];

    /**
     * Open a Core API bank_transfer VA charge for the installment and return the
     * pay instruction. order_id is derived deterministically from the installment
     * (the bridge that verifyCallback maps back). gross_amount is the exact
     * installment amount as an INTEGER of rupiah — Midtrans IDR carries no
     * decimals. A non-2xx or a response without a VA raises PaymentException so
     * the caller never stores a fake charge.
     */
    public function createCharge(Installment $installment): PaymentInstruction
    {
        $orderId = $this->orderIdFor($installment);
        $amount = BigDecimal::of((string) $installment->amount);

        // Midtrans IDR gross_amount is an integer (no decimals); our termin
        // amounts are exact rupiah — round to the nearest whole rupiah safely.
        $grossInt = (int) (string) $amount->toScale(0, RoundingMode::HALF_UP);

        $base = config('payment.midtrans.is_production', false)
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';

        $response = Http::withBasicAuth((string) config('payment.midtrans.server_key', ''), '')
            ->acceptJson()
            ->asJson()
            ->post("{$base}/v2/charge", [
                'payment_type' => 'bank_transfer',
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $grossInt,
                ],
                'bank_transfer' => [
                    'bank' => (string) config('payment.midtrans.bank', 'bca'),
                ],
            ]);

        if (! $response->successful()) {
            throw PaymentException::chargeFailed('HTTP '.$response->status());
        }

        $vaNumber = $this->extractVaNumber($response->json());

        if ($vaNumber === null) {
            throw PaymentException::chargeFailed('VA tidak ditemukan pada respons gateway.');
        }

        return new PaymentInstruction(
            vaNumber: $vaNumber,
            gatewayRef: $orderId,
            amount: (string) $amount->toScale(2, RoundingMode::HALF_UP),
        );
    }

    /**
     * Verify a Midtrans notification and build the settlement.
     *
     * Signature (Midtrans): SHA512(order_id + status_code + gross_amount +
     * ServerKey), compared with the payload's `signature_key` via hash_equals
     * (constant-time — never `==`, to avoid a timing side channel). A missing
     * field, an absent/empty ServerKey, or a mismatched signature is rejected
     * WITHOUT touching any state — the webhook turns it into a 401.
     *
     * Only after the signature checks out is `paid` derived from the transaction
     * status (+ fraud status). Optionally (config toggle) the transaction is then
     * re-confirmed against Midtrans' status API, so a leaked ServerKey alone
     * cannot forge a settlement.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): PaymentSettlement
    {
        $orderId = $this->stringField($payload, 'order_id');
        $statusCode = $this->stringField($payload, 'status_code');
        $grossAmount = $this->stringField($payload, 'gross_amount');
        $signatureKey = $this->stringField($payload, 'signature_key');
        $serverKey = (string) config('payment.midtrans.server_key', '');

        // Any missing field, or no configured ServerKey, means we cannot verify.
        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '' || $serverKey === '') {
            throw PaymentException::invalidCallback();
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        if (! hash_equals($expected, $signatureKey)) {
            throw PaymentException::invalidCallback();
        }

        // Signature is authentic. Derive paid from the notification, then (if
        // enabled) re-confirm against the status API as defense-in-depth.
        $paid = $this->isPaid(
            $this->stringField($payload, 'transaction_status'),
            array_key_exists('fraud_status', $payload) ? (string) $payload['fraud_status'] : null,
        );

        if ($paid && config('payment.midtrans.verify_status', true)) {
            $paid = $this->confirmViaStatusApi($orderId);
        }

        return new PaymentSettlement(gatewayRef: $orderId, paid: $paid);
    }

    /**
     * Whether a (status, fraud) pair means the money is actually captured.
     * settlement/capture only, and — when a fraud_status is present — it must be
     * `accept`. A `challenge`/`deny` (or any non-accept) fraud status is never
     * paid, so a captured-but-challenged card is not settled.
     */
    private function isPaid(string $transactionStatus, ?string $fraudStatus): bool
    {
        if (! in_array($transactionStatus, self::PAID_STATUSES, true)) {
            return false; // pending / expire / deny / cancel / failure / refund …
        }

        return $fraudStatus === null || $fraudStatus === 'accept';
    }

    /**
     * Re-confirm the transaction directly with Midtrans (GET /v2/{order_id}/status),
     * authenticated with the ServerKey (HTTP Basic: key as username, empty
     * password). Re-derives `paid` from the authoritative response so a spoofed
     * notification cannot settle even if the ServerKey leaks.
     *
     * [perlu verifikasi] exact base host/paths against the current Midtrans docs
     * at go-live. Never called in tests unless verify_status is toggled on (then
     * the HTTP client is faked — no real network).
     */
    private function confirmViaStatusApi(string $orderId): bool
    {
        $base = config('payment.midtrans.is_production', false)
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';

        $response = Http::withBasicAuth((string) config('payment.midtrans.server_key', ''), '')
            ->acceptJson()
            ->get("{$base}/v2/{$orderId}/status");

        // If we cannot confirm, do NOT settle — fail safe.
        if (! $response->successful()) {
            return false;
        }

        return $this->isPaid(
            (string) ($response->json('transaction_status') ?? ''),
            $response->json('fraud_status') !== null ? (string) $response->json('fraud_status') : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        return trim((string) ($payload[$key] ?? ''));
    }

    /** Deterministic order_id for a term — the same value verifyCallback maps back. */
    private function orderIdFor(Installment $installment): string
    {
        return sprintf('%s-%d', (string) config('payment.midtrans.order_prefix', 'CM'), $installment->id);
    }

    /**
     * Pull the VA number from a Core API bank_transfer response. BCA/BNI/BRI use
     * `va_numbers[0].va_number`; Permata uses `permata_va_number`.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function extractVaNumber(?array $body): ?string
    {
        $va = $body['va_numbers'][0]['va_number'] ?? $body['permata_va_number'] ?? null;

        return is_string($va) && $va !== '' ? $va : null;
    }
}
