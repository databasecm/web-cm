<?php

namespace App\Filament\Pages;

use App\Enums\Bidang;
use App\Models\Role;
use App\Services\GuestConsultationStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Live inbox for guest (no-login) consultations (B4, ADR-0003).
 *
 * Reads the per-bidang active index from Redis and polls for updates — there are
 * no websockets. A Manager only ever sees sessions in its own bidang; Owner and
 * Direktur oversee every bidang without claiming. Replying appends a
 * sender_type=manager message to Redis and, for a Manager, claims the session on
 * first response (set manager_id) — mirroring the persisted-thread behaviour in
 * B2. Nothing here ever writes to the database.
 */
class GuestConsultations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Konsultasi Tamu';

    protected static ?string $title = 'Konsultasi Tamu (live)';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'filament.pages.guest-consultations';

    /** Currently opened session token, or null. */
    public ?string $activeToken = null;

    /** Draft reply for the open session. */
    public string $reply = '';

    /**
     * Only consultation-handling staff reach this page: Owner, Direktur, Manager.
     * Finance/HR/Mitra/Mandor/Konsumen never do (mirrors ConsultationPolicy).
     */
    public static function canAccess(): bool
    {
        return self::actorIsStaff();
    }

    protected static function actorIsStaff(): bool
    {
        $actor = auth()->user();

        return $actor !== null
            && (in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true) || $actor->isManager());
    }

    /**
     * The business units the current actor may see guest sessions for: a Manager
     * is confined to its own bidang; Owner/Direktur span every unit.
     *
     * @return array<int, Bidang>
     */
    protected function bidangScope(): array
    {
        $actor = auth()->user();

        if ($actor?->isManager() && $actor->bidang !== null) {
            return [$actor->bidang];
        }

        return Bidang::cases();
    }

    /**
     * Whether a token's session is within the actor's bidang scope. Re-checked
     * server-side so a Manager cannot reach another bidang's session by token.
     */
    protected function inScope(string $token): bool
    {
        $meta = $this->store()->meta($token);

        if ($meta === null) {
            return false;
        }

        $bidang = Bidang::tryFrom($meta['bidang']);

        return $bidang !== null && in_array($bidang, $this->bidangScope(), true);
    }

    /**
     * Live guest sessions in the actor's scope, newest activity first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessions(): array
    {
        $store = $this->store();
        $sessions = [];

        foreach ($this->bidangScope() as $bidang) {
            foreach ($store->liveSessions($bidang) as $session) {
                $sessions[] = $session;
            }
        }

        usort($sessions, static fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);

        return $sessions;
    }

    /**
     * Messages of the open session (empty when none open or it has expired).
     *
     * @return array<int, array<string, mixed>>
     */
    public function messages(): array
    {
        if ($this->activeToken === null) {
            return [];
        }

        return $this->store()->read($this->activeToken)['messages'];
    }

    /**
     * Open a session for viewing/replying, enforcing the bidang scope.
     */
    public function openSession(string $token): void
    {
        if (! $this->inScope($token)) {
            $this->activeToken = null;

            return;
        }

        $this->activeToken = $token;
        $this->reply = '';
    }

    /**
     * Send a Manager reply into the open guest session. Claims the session for a
     * Manager on first response; overseers reply without claiming.
     */
    public function send(): void
    {
        $token = $this->activeToken;

        if ($token === null || ! $this->inScope($token) || ! $this->store()->exists($token)) {
            Notification::make()->title('Sesi tamu sudah berakhir.')->warning()->send();
            $this->activeToken = null;

            return;
        }

        $message = trim($this->reply);

        if ($message === '') {
            return;
        }

        $store = $this->store();
        $store->appendManagerReply($token, $message);

        // Claim-on-respond: only a Manager claims, and only the first responder
        // (the store sets manager_id solely when still unset). Overseers
        // (Owner/Direktur) reply without ever claiming — consistent with B2.
        $actor = auth()->user();
        if ($actor->isManager()) {
            $store->claim($token, $actor->id);
        }

        $this->reply = '';

        Notification::make()->title('Balasan terkirim')->success()->send();
    }

    protected function store(): GuestConsultationStore
    {
        return app(GuestConsultationStore::class);
    }
}
