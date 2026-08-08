<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentException;
use App\Models\Installment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real Midtrans gateway (Core API / VA) behind the PaymentGateway interface
 * (ADR-0012/0013). Selected only when PAYMENT_GATEWAY=midtrans in the production
 * environment; the credential-free SimulatedGateway remains the default. No
 * third-party SDK — HTTP-direct (decision G-1).
 *
 * G-2 implements verifyCallback(): the gate between a real payment and a forged
 * one. G-3 will add createCharge() (Core API VA).
 */
class MidtransGateway implements PaymentGateway
{
    /** Transaction statuses that can mean "paid" (subject to the fraud check). */
    private const PAID_STATUSES = ['settlement', 'capture'];

    public function createCharge(Installment $installment): PaymentInstruction
    {
        throw new RuntimeException('MidtransGateway::createCharge belum diimplementasikan (Fase G-3).');
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
}
