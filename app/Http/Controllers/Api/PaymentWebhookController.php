<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentCallback;
use App\Services\Payment\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public payment-gateway callback endpoint (Fase 3-6). No consumer auth — this is
 * the gateway's channel; trust comes from verifying the callback signature, not a
 * login.
 *
 * The signature is verified synchronously; only a verified callback is queued for
 * settlement, so the endpoint answers 200 fast. A payload that fails verification
 * is rejected (401) and never touches any state. Settlement itself is idempotent
 * and §7-safe (see {@see ProcessPaymentCallback}).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway): JsonResponse
    {
        try {
            $settlement = $gateway->verifyCallback($request->all());
        } catch (PaymentException $e) {
            // Invalid signature/reference — do not change any state.
            Log::warning('payment.webhook.rejected', [
                'gateway_ref' => $request->input('gateway_ref'),
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Callback tidak sah.'], 401);
        }

        Log::info('payment.webhook.accepted', [
            'gateway_ref' => $settlement->gatewayRef,
            'paid' => $settlement->paid,
        ]);

        // Settle off the request cycle so the gateway gets a fast 200.
        ProcessPaymentCallback::dispatch($settlement->gatewayRef, $settlement->paid);

        return response()->json(['message' => 'Diterima.'], 200);
    }
}
