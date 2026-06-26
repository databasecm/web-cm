<?php

namespace App\Services;

use App\Enums\Bidang;
use App\Enums\SenderType;
use App\Exceptions\GuestSessionNotFoundException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Ephemeral store for guest (no-login) consultation sessions (ADR-0003).
 *
 * Lives ENTIRELY in Redis — it never touches Eloquent or the database. A session
 * exists only while its sliding TTL is refreshed (every message or poll); when a
 * guest closes the page the polling stops, the TTL lapses and every trace
 * disappears. Persisting a guest conversation only ever happens later, on an
 * explicit deal, by copying the transcript once (B5) — never from here.
 *
 * Keys (all under the Redis facade's configured prefix):
 * - guest:chat:{token}:meta  — hash: bidang, status, started_at, last_seen
 * - guest:chat:{token}:msgs  — list of JSON {sender_type, message, ts}
 * - guest:active:{bidang}    — sorted set, score = last_seen, member = token
 *
 * Routing is at the bidang level; `manager_id` is NOT set here — a Manager claims
 * the session only when first responding (B4).
 */
class GuestConsultationStore
{
    /** Default sliding window before an idle guest session expires. */
    public const TTL_SECONDS = 1800; // 30 minutes

    public function __construct(private int $ttl = self::TTL_SECONDS) {}

    /**
     * Start a session with the guest's first message (called lazily, only once
     * the guest actually sends — never on a mere page visit). Returns the opaque
     * token the client keeps in sessionStorage.
     *
     * @return array{token: string, bidang: string, message: array, cursor: int}
     */
    public function start(Bidang $bidang, string $message): array
    {
        $token = (string) Str::uuid();
        $now = $this->now();

        Redis::hmset($this->metaKey($token), [
            'bidang' => $bidang->value,
            'status' => 'open',
            'started_at' => $now,
            'last_seen' => $now,
        ]);

        $stored = $this->pushMessage($token, SenderType::Konsumen, $message, $now);
        $this->touch($token, $bidang->value, $now);

        return [
            'token' => $token,
            'bidang' => $bidang->value,
            'message' => $stored,
            'cursor' => 1,
        ];
    }

    /**
     * Append a guest message to a live session and refresh its TTL.
     *
     * @return array{message: array, cursor: int}
     */
    public function append(string $token, string $message): array
    {
        $meta = $this->requireSession($token);
        $now = $this->now();

        $stored = $this->pushMessage($token, SenderType::Konsumen, $message, $now);
        $this->touch($token, $meta['bidang'], $now);

        return [
            'message' => $stored,
            'cursor' => (int) Redis::llen($this->msgsKey($token)),
        ];
    }

    /**
     * Read messages after a cursor (the number of messages the client has
     * already seen). Polling doubles as keepalive: it refreshes the sliding TTL
     * and last_seen, which is also how a Manager detects a live session (B4).
     *
     * @return array{messages: array<int, array>, cursor: int, status: string, bidang: string}
     */
    public function fetch(string $token, int $after = 0): array
    {
        $meta = $this->requireSession($token);
        $now = $this->now();

        $raw = Redis::lrange($this->msgsKey($token), max($after, 0), -1);
        $messages = array_map(static fn (string $json): array => json_decode($json, true), $raw);

        $this->touch($token, $meta['bidang'], $now);

        return [
            'messages' => $messages,
            'cursor' => max($after, 0) + count($messages),
            'status' => $meta['status'],
            'bidang' => $meta['bidang'],
        ];
    }

    /**
     * Whether a session is still live (its meta key has not expired).
     */
    public function exists(string $token): bool
    {
        return (bool) Redis::exists($this->metaKey($token));
    }

    /**
     * Session metadata, or null when the session has ended.
     *
     * @return array<string, string>|null
     */
    public function meta(string $token): ?array
    {
        $meta = Redis::hgetall($this->metaKey($token));

        return $meta !== [] ? $meta : null;
    }

    /**
     * Tokens with activity within the TTL window for a bidang — i.e. the live
     * sessions a Manager of that bidang may pick up (B4). Stale members (last
     * seen older than the window) are pruned opportunistically.
     *
     * @return array<int, string>
     */
    public function activeTokens(Bidang $bidang): array
    {
        $key = $this->activeKey($bidang->value);
        $cutoff = $this->now() - $this->ttl;

        // Drop members strictly older than the window (ZSETs have no per-member
        // TTL, so the index is pruned by score on read).
        Redis::zremrangebyscore($key, '-inf', "({$cutoff}");

        return Redis::zrangebyscore($key, $cutoff, '+inf');
    }

    /**
     * @return array{sender_type: string, message: string, ts: float}
     */
    private function pushMessage(string $token, SenderType $sender, string $message, float $ts): array
    {
        $payload = ['sender_type' => $sender->value, 'message' => $message, 'ts' => $ts];
        Redis::rpush($this->msgsKey($token), json_encode($payload));

        return $payload;
    }

    /**
     * Refresh the sliding TTL and last_seen, and (re)index the session as active.
     */
    private function touch(string $token, string $bidang, float $now): void
    {
        Redis::hset($this->metaKey($token), 'last_seen', $now);
        Redis::expire($this->metaKey($token), $this->ttl);
        Redis::expire($this->msgsKey($token), $this->ttl);
        Redis::zadd($this->activeKey($bidang), $now, $token);
    }

    /**
     * @return array<string, string>
     */
    private function requireSession(string $token): array
    {
        $meta = $this->meta($token);

        if ($meta === null) {
            throw new GuestSessionNotFoundException($token);
        }

        return $meta;
    }

    private function metaKey(string $token): string
    {
        return "guest:chat:{$token}:meta";
    }

    private function msgsKey(string $token): string
    {
        return "guest:chat:{$token}:msgs";
    }

    private function activeKey(string $bidang): string
    {
        return "guest:active:{$bidang}";
    }

    private function now(): float
    {
        return microtime(true);
    }
}
