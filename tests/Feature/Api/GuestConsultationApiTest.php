<?php

use App\Models\Consultation;
use App\Models\ConsultationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::connection()->flushdb();
});

// ---------------------------------------------------------------------------
// Happy path over HTTP
// ---------------------------------------------------------------------------

it('starts, appends and polls a guest session over the API', function () {
    $start = $this->postJson('/api/v1/consultations/guest', [
        'bidang' => 'cufid',
        'message' => 'Halo, mau konsultasi.',
    ]);

    $start->assertCreated()
        ->assertJsonPath('bidang', 'cufid')
        ->assertJsonPath('cursor', 1)
        ->assertJsonPath('message.sender_type', 'konsumen');

    $token = $start->json('token');

    $this->postJson("/api/v1/consultations/guest/{$token}/messages", [
        'message' => 'Pesan kedua.',
    ])->assertCreated()->assertJsonPath('cursor', 2);

    $this->getJson("/api/v1/consultations/guest/{$token}/messages?after=1")
        ->assertOk()
        ->assertJsonPath('cursor', 2)
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.message', 'Pesan kedua.');
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects a start without a valid bidang or message', function () {
    $this->postJson('/api/v1/consultations/guest', ['message' => 'tanpa bidang'])
        ->assertStatus(422)->assertJsonValidationErrors('bidang');

    $this->postJson('/api/v1/consultations/guest', ['bidang' => 'tidak-ada', 'message' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('bidang');

    $this->postJson('/api/v1/consultations/guest', ['bidang' => 'cufid'])
        ->assertStatus(422)->assertJsonValidationErrors('message');
});

// ---------------------------------------------------------------------------
// Unknown / expired token → 404 (never leaks existence)
// ---------------------------------------------------------------------------

it('returns 404 for an unknown token on append and fetch', function () {
    $this->getJson('/api/v1/consultations/guest/ghost-token/messages')
        ->assertNotFound();

    $this->postJson('/api/v1/consultations/guest/ghost-token/messages', ['message' => 'halo'])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Throttle
// ---------------------------------------------------------------------------

it('throttles the guest API per IP', function () {
    // 60 requests/minute; the 61st is rejected.
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/consultations/guest', [
            'bidang' => 'cufid',
            'message' => "spam {$i}",
        ])->assertCreated();
    }

    $this->postJson('/api/v1/consultations/guest', [
        'bidang' => 'cufid',
        'message' => 'over the limit',
    ])->assertStatus(429);
});

// ---------------------------------------------------------------------------
// HARD INVARIANT — the full HTTP guest flow writes nothing to the database
// ---------------------------------------------------------------------------

it('writes nothing to the database across the full HTTP guest flow', function () {
    $token = $this->postJson('/api/v1/consultations/guest', [
        'bidang' => 'cc',
        'message' => 'Pesan 1',
    ])->json('token');

    $this->postJson("/api/v1/consultations/guest/{$token}/messages", ['message' => 'Pesan 2']);
    $this->getJson("/api/v1/consultations/guest/{$token}/messages");

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationMessage::count())->toBe(0);
});
