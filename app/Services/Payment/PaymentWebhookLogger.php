<?php

namespace App\Services\Payment;

use App\Models\PaymentWebhookLog;
use Throwable;

/**
 * Writes the payment-webhook audit trail (ADR-0013). Observability, NOT part of
 * the money transaction: a failed write is swallowed + reported so it can never
 * block a settlement (same posture as the notification dispatcher, 7-3).
 *
 * Every stored payload is redacted first — signature material is never persisted.
 */
class PaymentWebhookLogger
{
    /** Payload keys that carry auth material and must never be stored. */
    private const REDACT_KEYS = ['signature_key', 'signature'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): void
    {
        try {
            PaymentWebhookLog::create($attributes + ['created_at' => now()]);
        } catch (Throwable $e) {
            // A logging failure must never break the webhook/settlement flow.
            report($e);
        }
    }

    /**
     * Strip signature material from a callback payload before it is stored.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        foreach (self::REDACT_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
