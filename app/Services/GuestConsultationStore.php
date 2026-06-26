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

    /** A guest seen within this window is considered "online" (B4 heuristic). */
    public const ONLINE_WITHIN_SECONDS = 30;

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
        $this->markGuestSeen($token, $now);
        $this->keepAlive($token, $bidang->value, $now);

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
        $this->markGuestSeen($token, $now);
        $this->keepAlive($token, $meta['bidang'], $now);

        return [
            'message' => $stored,
            'cursor' => (int) Redis::llen($this->msgsKey($token)),
        ];
    }

    /**
     * Read messages after a cursor (the number of messages the client has
     * already seen). A guest poll doubles as keepalive AND a presence signal:
     * it refreshes the sliding TTL and bumps last_seen, which is what the
     * Manager inbox uses to mark the guest "online" (B4).
     *
     * @return array{messages: array<int, array>, cursor: int, status: string, bidang: string}
     */
    public function fetch(string $token, int $after = 0): array
    {
        $meta = $this->requireSession($token);
        $now = $this->now();

        $raw = Redis::lrange($this->msgsKey($token), max($after, 0), -1);
        $messages = array_map(static fn (string $json): array => json_decode($json, true), $raw);

        $this->markGuestSeen($token, $now);
        $this->keepAlive($token, $meta['bidang'], $now);

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
     * Live guest sessions for a bidang, newest activity first — the Manager
     * inbox view (B4). Each entry carries an `online` heuristic (guest seen
     * within the online window) and the claiming `manager_id`, if any.
     *
     * @return array<int, array{token: string, bidang: string, status: string, started_at: float, last_seen: float, manager_id: int|null, online: bool, message_count: int}>
     */
    public function liveSessions(Bidang $bidang): array
    {
        $now = $this->now();
        $sessions = [];

        foreach ($this->activeTokens($bidang) as $token) {
            $meta = $this->meta($token);

            if ($meta === null) {
                continue;
            }

            $lastSeen = (float) $meta['last_seen'];

            $sessions[] = [
                'token' => $token,
                'bidang' => $meta['bidang'],
                'status' => $meta['status'],
                'started_at' => (float) $meta['started_at'],
                'last_seen' => $lastSeen,
                'manager_id' => isset($meta['manager_id']) ? (int) $meta['manager_id'] : null,
                'online' => ($now - $lastSeen) < self::ONLINE_WITHIN_SECONDS,
                'message_count' => (int) Redis::llen($this->msgsKey($token)),
            ];
        }

        usort($sessions, static fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);

        return $sessions;
    }

    /**
     * Read a session's transcript for the Manager view. A pure read: it does not
     * extend the TTL or mark the guest seen, so a Manager merely watching never
     * keeps an abandoned session alive. Returns exists=false once it has expired.
     *
     * @return array{exists: bool, messages: array<int, array>, status: string|null, bidang: string|null, manager_id: int|null}
     */
    public function read(string $token): array
    {
        $meta = $this->meta($token);

        if ($meta === null) {
            return ['exists' => false, 'messages' => [], 'status' => null, 'bidang' => null, 'manager_id' => null];
        }

        $raw = Redis::lrange($this->msgsKey($token), 0, -1);

        return [
            'exists' => true,
            'messages' => array_map(static fn (string $json): array => json_decode($json, true), $raw),
            'status' => $meta['status'],
            'bidang' => $meta['bidang'],
            'manager_id' => isset($meta['manager_id']) ? (int) $meta['manager_id'] : null,
        ];
    }

    /**
     * Append a Manager reply to a live session. Refreshes the session's liveness
     * (so the guest still has a window to return and read it) but NOT the guest
     * presence marker. Mirrors the persisted-thread reply in B2.
     *
     * @return array{message: array, cursor: int}
     */
    public function appendManagerReply(string $token, string $message): array
    {
        $meta = $this->requireSession($token);
        $now = $this->now();

        $stored = $this->pushMessage($token, SenderType::Manager, $message, $now);
        $this->keepAlive($token, $meta['bidang'], $now);

        return [
            'message' => $stored,
            'cursor' => (int) Redis::llen($this->msgsKey($token)),
        ];
    }

    /**
     * Claim a session for a Manager on first response — set manager_id only if it
     * is not already set (ADR-0003). Returns whether this call performed the
     * claim. Overseers (Owner/Direktur) deliberately never call this.
     */
    public function claim(string $token, int $managerId): bool
    {
        $meta = $this->requireSession($token);

        if (isset($meta['manager_id']) && $meta['manager_id'] !== '') {
            return false;
        }

        Redis::hset($this->metaKey($token), 'manager_id', $managerId);

        return true;
    }

    /**
     * Drop a session entirely — its meta, messages and active-index entry. Used
     * once a guest session has been promoted to a persisted consultation on deal
     * (B5): the ephemeral copy must not linger after it has been persisted.
     */
    public function forget(string $token): void
    {
        $meta = $this->meta($token);

        if ($meta !== null) {
            Redis::zrem($this->activeKey($meta['bidang']), $token);
        }

        Redis::del($this->metaKey($token));
        Redis::del($this->msgsKey($token));
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
     * Refresh session liveness: extend the sliding TTL and (re)index the session
     * as active so it stays in the Manager inbox. Driven by BOTH sides — a guest
     * message/poll and a Manager reply all keep the session alive. Deliberately
     * does NOT touch last_seen (that is guest presence, see markGuestSeen).
     */
    private function keepAlive(string $token, string $bidang, float $now): void
    {
        Redis::expire($this->metaKey($token), $this->ttl);
        Redis::expire($this->msgsKey($token), $this->ttl);
        Redis::zadd($this->activeKey($bidang), $now, $token);
    }

    /**
     * Record guest presence. Only guest-side activity (start/append/poll) updates
     * last_seen, so the "online" heuristic reflects the guest actually being on
     * the page — a Manager reading or replying never makes the guest look online.
     */
    private function markGuestSeen(string $token, float $now): void
    {
        Redis::hset($this->metaKey($token), 'last_seen', $now);
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
