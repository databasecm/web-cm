<?php

use App\Enums\Bidang;
use App\Enums\SenderType;
use App\Exceptions\GuestSessionNotFoundException;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Services\GuestConsultationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::connection()->flushdb();
    $this->store = new GuestConsultationStore;
});

function gMetaKey(string $token): string
{
    return "guest:chat:{$token}:meta";
}

// ---------------------------------------------------------------------------
// liveSessions — inbox view + online heuristic
// ---------------------------------------------------------------------------

it('lists live sessions for a bidang with an online heuristic', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];

    $sessions = $this->store->liveSessions(Bidang::Cufid);
    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]['token'])->toBe($token)
        ->and($sessions[0]['online'])->toBeTrue()
        ->and($sessions[0]['message_count'])->toBe(1)
        ->and($sessions[0]['manager_id'])->toBeNull();

    // A guest last seen beyond the online window reads as idle, still listed.
    Redis::hset(gMetaKey($token), 'last_seen', microtime(true) - 120);
    $idle = $this->store->liveSessions(Bidang::Cufid);
    expect($idle)->toHaveCount(1)
        ->and($idle[0]['online'])->toBeFalse();
});

it('scopes live sessions to their own bidang', function () {
    $cufid = $this->store->start(Bidang::Cufid, 'A')['token'];
    $cc = $this->store->start(Bidang::Cc, 'B')['token'];

    expect(collect($this->store->liveSessions(Bidang::Cufid))->pluck('token')->all())
        ->toBe([$cufid]);
    expect(collect($this->store->liveSessions(Bidang::Cc))->pluck('token')->all())
        ->toBe([$cc]);
});

// ---------------------------------------------------------------------------
// read — pure, no liveness/presence side effects
// ---------------------------------------------------------------------------

it('reads a transcript without extending TTL or guest presence', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];
    $seenBefore = $this->store->meta($token)['last_seen'];

    Redis::expire(gMetaKey($token), 5); // simulate the window decaying

    $read = $this->store->read($token);
    expect($read['exists'])->toBeTrue()
        ->and($read['messages'])->toHaveCount(1)
        // a Manager merely reading must NOT keep an abandoned session alive
        ->and(Redis::ttl(gMetaKey($token)))->toBeLessThanOrEqual(5)
        // nor make the guest look freshly seen
        ->and($this->store->meta($token)['last_seen'])->toBe($seenBefore);
});

it('reports a vanished session as not existing on read', function () {
    expect($this->store->read('ghost'))->toMatchArray(['exists' => false, 'messages' => []]);
});

// ---------------------------------------------------------------------------
// appendManagerReply — keeps session alive, does not fake guest presence
// ---------------------------------------------------------------------------

it('appends a manager reply, refreshes TTL, but leaves guest presence untouched', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];
    $seenBefore = $this->store->meta($token)['last_seen'];

    Redis::expire(gMetaKey($token), 5);

    $result = $this->store->appendManagerReply($token, 'Selamat datang!');
    expect($result['cursor'])->toBe(2)
        ->and($result['message']['sender_type'])->toBe(SenderType::Manager->value)
        // liveness refreshed so the guest can still return and read it
        ->and(Redis::ttl(gMetaKey($token)))->toBeGreaterThan(1000)
        // but last_seen (guest presence) is unchanged — no false "online"
        ->and($this->store->meta($token)['last_seen'])->toBe($seenBefore);

    $messages = $this->store->read($token)['messages'];
    expect($messages[1]['message'])->toBe('Selamat datang!')
        ->and($messages[1]['sender_type'])->toBe('manager');
});

it('throws when replying to a vanished session', function () {
    expect(fn () => $this->store->appendManagerReply('ghost', 'halo'))
        ->toThrow(GuestSessionNotFoundException::class);
});

// ---------------------------------------------------------------------------
// claim — first responder only
// ---------------------------------------------------------------------------

it('claims a session on first call only', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];

    expect($this->store->claim($token, 42))->toBeTrue()
        ->and((int) $this->store->meta($token)['manager_id'])->toBe(42)
        // a second claim (e.g. another Manager) is a no-op
        ->and($this->store->claim($token, 99))->toBeFalse()
        ->and((int) $this->store->meta($token)['manager_id'])->toBe(42);
});

// ---------------------------------------------------------------------------
// Zero-DB invariant across the manager-side flow
// ---------------------------------------------------------------------------

it('writes nothing to the database when a manager replies and claims', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];
    $this->store->appendManagerReply($token, 'Balasan');
    $this->store->claim($token, 7);
    $this->store->read($token);
    $this->store->liveSessions(Bidang::Cufid);

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationMessage::count())->toBe(0);
});
