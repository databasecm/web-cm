<?php

use App\Exceptions\PaymentException;
use App\Services\Payment\MidtransGateway;
use App\Services\Payment\SimulatedGateway;
use Illuminate\Support\Facades\Http;

/*
| Fase G-2 — MidtransGateway::verifyCallback: real SHA512 signature verification,
| the gate between a genuine payment and a forged one. Unit-level, a TEST
| ServerKey in config, NO network (status-API toggle OFF except one faked case).
*/

beforeEach(function () {
    config([
        'payment.midtrans.server_key' => 'SB-Mid-server-TESTKEY-do-not-use',
        'payment.midtrans.verify_status' => false, // no network in the core cases
    ]);
    $this->gw = new MidtransGateway;
});

/** A correctly-signed Midtrans notification for the given fields. */
function mtPayload(array $overrides = []): array
{
    $p = array_merge([
        'order_id' => 'CM-INST-00000042',
        'status_code' => '200',
        'gross_amount' => '300000.00',
        'transaction_status' => 'settlement',
    ], $overrides);

    $serverKey = (string) config('payment.midtrans.server_key');
    $p['signature_key'] = hash('sha512', $p['order_id'].$p['status_code'].$p['gross_amount'].$serverKey);

    return $p;
}

// ---------------------------------------------------------------------------
// Valid signature → paid derived correctly
// ---------------------------------------------------------------------------

it('settles a valid settlement notification (paid, correct ref)', function () {
    $settlement = $this->gw->verifyCallback(mtPayload());

    expect($settlement->paid)->toBeTrue()
        ->and($settlement->gatewayRef)->toBe('CM-INST-00000042');
});

it('settles a valid capture with fraud_status accept', function () {
    $settlement = $this->gw->verifyCallback(mtPayload([
        'transaction_status' => 'capture',
        'fraud_status' => 'accept',
    ]));

    expect($settlement->paid)->toBeTrue();
});

it('does not settle pending / expire / deny / cancel', function () {
    foreach (['pending', 'expire', 'deny', 'cancel', 'failure'] as $status) {
        $settlement = $this->gw->verifyCallback(mtPayload(['transaction_status' => $status]));
        expect($settlement->paid)->toBeFalse("{$status} must not be paid")
            ->and($settlement->gatewayRef)->toBe('CM-INST-00000042'); // ref still parsed
    }
});

it('does not settle a captured-but-not-accepted card (challenge/deny)', function () {
    foreach (['challenge', 'deny'] as $fraud) {
        $settlement = $this->gw->verifyCallback(mtPayload([
            'transaction_status' => 'capture',
            'fraud_status' => $fraud,
        ]));
        expect($settlement->paid)->toBeFalse("capture+{$fraud} must not be paid");
    }
});

// ---------------------------------------------------------------------------
// Invalid signature / missing fields → rejected, NO settlement
// ---------------------------------------------------------------------------

it('rejects a tampered payload (signature no longer matches)', function () {
    $payload = mtPayload();
    $payload['gross_amount'] = '1.00'; // changed after signing → stale signature

    expect(fn () => $this->gw->verifyCallback($payload))->toThrow(PaymentException::class);
});

it('rejects a forged signature_key', function () {
    $payload = mtPayload();
    $payload['signature_key'] = str_repeat('a', 128); // plausible-looking sha512, but wrong

    expect(fn () => $this->gw->verifyCallback($payload))->toThrow(PaymentException::class);
});

it('rejects when signature_key or a signed field is missing', function () {
    $missingSig = mtPayload();
    unset($missingSig['signature_key']);
    expect(fn () => $this->gw->verifyCallback($missingSig))->toThrow(PaymentException::class);

    $missingOrder = mtPayload();
    unset($missingOrder['order_id']);
    expect(fn () => $this->gw->verifyCallback($missingOrder))->toThrow(PaymentException::class);
});

it('rejects when no ServerKey is configured (cannot verify → fail safe)', function () {
    config(['payment.midtrans.server_key' => '']);

    expect(fn () => $this->gw->verifyCallback(mtPayload()))->toThrow(PaymentException::class);
});

// ---------------------------------------------------------------------------
// Constant-time comparison — hash_equals, never ==
// ---------------------------------------------------------------------------

it('compares the signature with hash_equals, not ==', function () {
    $source = file_get_contents(app_path('Services/Payment/MidtransGateway.php'));
    expect($source)->toContain('hash_equals(')
        ->and($source)->not->toMatch('/==\s*\$signatureKey/');
});

// ---------------------------------------------------------------------------
// Status-API confirmation (defense-in-depth) — faked HTTP, no real network
// ---------------------------------------------------------------------------

it('confirms via the status API when it agrees the transaction settled', function () {
    config(['payment.midtrans.verify_status' => true]);
    Http::fake(['*/v2/*/status' => Http::response(['transaction_status' => 'settlement'], 200)]);

    expect($this->gw->verifyCallback(mtPayload())->paid)->toBeTrue();
});

it('overrides a settlement notification when the status API says otherwise', function () {
    config(['payment.midtrans.verify_status' => true]);
    // Signature valid + notification says settlement, but authoritative status is pending.
    Http::fake(['*/v2/*/status' => Http::response(['transaction_status' => 'pending'], 200)]);

    expect($this->gw->verifyCallback(mtPayload())->paid)->toBeFalse();
});

it('fails safe (not paid) when the status API is unreachable', function () {
    config(['payment.midtrans.verify_status' => true]);
    Http::fake(['*/v2/*/status' => Http::response([], 500)]);

    expect($this->gw->verifyCallback(mtPayload())->paid)->toBeFalse();
});

// ---------------------------------------------------------------------------
// The simulated gateway is untouched (its own signing still verifies)
// ---------------------------------------------------------------------------

it('leaves the simulated gateway working independently', function () {
    $sim = new SimulatedGateway;
    $payload = ['gateway_ref' => 'SIM-CHG-00000009', 'status' => 'paid', 'signature' => $sim->sign('SIM-CHG-00000009')];

    $settlement = $sim->verifyCallback($payload);
    expect($settlement->paid)->toBeTrue()
        ->and($settlement->gatewayRef)->toBe('SIM-CHG-00000009');
});
