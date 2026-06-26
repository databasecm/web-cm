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
    // Isolated, throwaway test Redis — clear guest keys between tests.
    Redis::connection()->flushdb();
    $this->store = new GuestConsultationStore;
});

function metaKey(string $token): string
{
    return "guest:chat:{$token}:meta";
}

// ---------------------------------------------------------------------------
// Lifecycle: start → append → fetch
// ---------------------------------------------------------------------------

it('starts a session lazily with the first guest message', function () {
    $result = $this->store->start(Bidang::Cufid, 'Halo, mau tanya furniture.');

    expect($result['token'])->toBeString()->toHaveLength(36)
        ->and($result['bidang'])->toBe('cufid')
        ->and($result['cursor'])->toBe(1)
        ->and($result['message']['sender_type'])->toBe(SenderType::Konsumen->value)
        ->and($result['message']['message'])->toBe('Halo, mau tanya furniture.');

    $meta = $this->store->meta($result['token']);
    expect($meta['bidang'])->toBe('cufid')
        ->and($meta['status'])->toBe('open')
        ->and($meta)->toHaveKeys(['started_at', 'last_seen']);
});

it('appends messages and advances the cursor', function () {
    $token = $this->store->start(Bidang::Cc, 'Pesan 1')['token'];

    $append = $this->store->append($token, 'Pesan 2');
    expect($append['cursor'])->toBe(2)
        ->and($append['message']['message'])->toBe('Pesan 2');

    $all = $this->store->fetch($token);
    expect($all['messages'])->toHaveCount(2)
        ->and($all['cursor'])->toBe(2)
        ->and($all['status'])->toBe('open')
        ->and($all['bidang'])->toBe('cc');
});

it('fetches only messages after the cursor', function () {
    $token = $this->store->start(Bidang::Solit, 'Pesan 1')['token'];
    $this->store->append($token, 'Pesan 2');
    $this->store->append($token, 'Pesan 3');

    $tail = $this->store->fetch($token, after: 1);
    expect($tail['messages'])->toHaveCount(2)
        ->and($tail['messages'][0]['message'])->toBe('Pesan 2')
        ->and($tail['messages'][1]['message'])->toBe('Pesan 3')
        ->and($tail['cursor'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Active index per bidang
// ---------------------------------------------------------------------------

it('indexes a live session only under its own bidang', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];

    expect($this->store->activeTokens(Bidang::Cufid))->toContain($token)
        ->and($this->store->activeTokens(Bidang::Cc))->not->toContain($token)
        ->and($this->store->activeTokens(Bidang::Solit))->toBeEmpty();
});

it('prunes stale tokens from the active index by score', function () {
    $live = $this->store->start(Bidang::Cufid, 'Masih hidup')['token'];

    // A token whose last activity is far outside the TTL window.
    Redis::zadd('guest:active:cufid', microtime(true) - 99999, 'stale-token');

    $active = $this->store->activeTokens(Bidang::Cufid);

    expect($active)->toContain($live)
        ->and($active)->not->toContain('stale-token')
        // and it was actually removed from the index, not just filtered
        ->and(Redis::zscore('guest:active:cufid', 'stale-token'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Sliding TTL
// ---------------------------------------------------------------------------

it('sets a TTL on start and refreshes it on every activity (sliding)', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];

    $ttl = Redis::ttl(metaKey($token));
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(GuestConsultationStore::TTL_SECONDS);

    // Simulate the window decaying, then prove activity pushes it back out.
    Redis::expire(metaKey($token), 5);
    expect(Redis::ttl(metaKey($token)))->toBeLessThanOrEqual(5);

    $this->store->fetch($token); // a poll is keepalive
    expect(Redis::ttl(metaKey($token)))->toBeGreaterThan(1000);
});

it('lets a session disappear once its TTL lapses', function () {
    $shortLived = new GuestConsultationStore(ttl: 1);
    $token = $shortLived->start(Bidang::Cufid, 'Sebentar saja')['token'];

    expect($shortLived->exists($token))->toBeTrue();

    usleep(1_200_000); // let the 1s TTL lapse

    expect($shortLived->exists($token))->toBeFalse()
        ->and(fn () => $shortLived->fetch($token))->toThrow(GuestSessionNotFoundException::class)
        ->and(fn () => $shortLived->append($token, 'halo?'))->toThrow(GuestSessionNotFoundException::class);
});

it('throws for an unknown token', function () {
    expect(fn () => $this->store->fetch('not-a-real-token'))
        ->toThrow(GuestSessionNotFoundException::class);
});

// ---------------------------------------------------------------------------
// HARD INVARIANT — the entire guest flow never writes to the database
// ---------------------------------------------------------------------------

it('never writes a row to consultations or consultation_messages', function () {
    $token = $this->store->start(Bidang::Cufid, 'Pesan tamu 1')['token'];
    $this->store->append($token, 'Pesan tamu 2');
    $this->store->fetch($token);
    $this->store->activeTokens(Bidang::Cufid);

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationMessage::count())->toBe(0);
});
