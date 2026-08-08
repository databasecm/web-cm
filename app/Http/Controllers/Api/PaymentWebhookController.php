<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentCallback;
use App\Models\PaymentWebhookLog;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentWebhookLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public payment-gateway callback endpoint (Fase 3-6). No consumer auth — this is
 * the gateway's channel; trust comes from verifying the callback signature, not a
 * login. Hardened with an IP allowlist + throttle (ADR-0013, G-4).
 *
 * The signature is verified synchronously; only a verified callback is queued for
 * settlement, so the endpoint answers 200 fast. A payload that fails verification
 * is rejected (401) and never touches any state. Every callback is written to the
 * audit trail (ADR-0013): the controller logs the REJECTED outcome; the job logs
 * the final outcome (settled/noop/unknown_ref). Settlement itself is idempotent
 * and §7-safe (see {@see ProcessPaymentCallback}).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentWebhookLogger $logger): JsonResponse
    {
        $redacted = $logger->redact($request->all());
        $ref = $request->input('order_id') ?? $request->input('gateway_ref');
        $status = $request->input('transaction_status') ?? $request->input('status');

        try {
            $settlement = $gateway->verifyCallback($request->all());
        } catch (PaymentException $e) {
            // Invalid signature/reference — do not change any state.
            Log::warning('payment.webhook.rejected', ['gateway_ref' => $ref, 'reason' => $e->getMessage()]);
            $logger->record([
                'gateway' => (string) config('payment.gateway'),
                'gateway_ref' => $ref,
                'transaction_status' => $status,
                'verified' => false,
                'outcome' => PaymentWebhookLog::OUTCOME_REJECTED,
                'ip' => $request->ip(),
                'payload' => $redacted,
            ]);

            return response()->json(['message' => 'Callback tidak sah.'], 401);
        }

        Log::info('payment.webhook.accepted', ['gateway_ref' => $settlement->gatewayRef, 'paid' => $settlement->paid]);

        // Settle off the request cycle so the gateway gets a fast 200. The job
        // writes the final audit outcome (it carries the redacted payload).
        ProcessPaymentCallback::dispatch($settlement->gatewayRef, $settlement->paid, $status, $redacted);

        return response()->json(['message' => 'Diterima.'], 200);
    }
}
