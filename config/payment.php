<?php

use App\Services\Payment\MidtransGateway;
use App\Services\Payment\SimulatedGateway;

/*
|--------------------------------------------------------------------------
| Payment gateway (ADR-0012 / ADR-0013)
|--------------------------------------------------------------------------
|
| One place selects which gateway sits behind the App\Services\Payment\
| PaymentGateway interface. The DEFAULT is the credential-free `simulated`
| gateway, so dev/test/CI run with no secrets. Flip PAYMENT_GATEWAY=midtrans
| ONLY in the production environment (+ fill the credentials below) — config
| only, no code change, exactly like MEDIA_DISK (A3).
|
| Credentials come from the environment and are NEVER committed — .env.example
| carries the keys without values.
|
*/

return [
    // Active gateway alias. Default 'simulated' (no credentials required).
    'gateway' => env('PAYMENT_GATEWAY', 'simulated'),

    // Alias → implementation. Both live side by side; the simulated one is never
    // removed (it powers dev/test and the Fase 3-6 webhook tests).
    'gateways' => [
        'simulated' => SimulatedGateway::class,
        'midtrans' => MidtransGateway::class,
    ],

    // Midtrans Core API (VA) credentials — filled only in the production env.
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),

        // After the callback SIGNATURE checks out, also confirm the transaction
        // against Midtrans' status API (defense-in-depth if the ServerKey leaks).
        // Recommended by Midtrans; toggle off only for isolated testing.
        'verify_status' => (bool) env('MIDTRANS_VERIFY_STATUS', true),
    ],

    // Public webhook endpoint hardening (ADR-0013). Enforced by middleware in a
    // later step; the values live here so IPs/limits are config, never hardcoded.
    'webhook' => [
        // Allowlist of gateway source IPs for POST /payments/webhook. Comma-
        // separated. EMPTY = allow all (dev/simulated). Production sets the
        // Midtrans notification IPs (from their docs — may change over time).
        'ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PAYMENT_WEBHOOK_IPS', ''))
        ))),

        // Rate limit for the public webhook endpoint.
        'throttle' => [
            'max_attempts' => (int) env('PAYMENT_WEBHOOK_MAX_ATTEMPTS', 60),
            'decay_seconds' => (int) env('PAYMENT_WEBHOOK_DECAY_SECONDS', 60),
        ],
    ],
];
