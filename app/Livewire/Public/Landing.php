<?php

namespace App\Livewire\Public;

use App\Enums\Bidang;
use App\Exceptions\GuestSessionNotFoundException;
use App\Services\GuestConsultationStore;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public company landing (Fase portal P-8) — the closer of the consumer
 * frontend. A single PUBLIC page (no login) that is the face of CV. Cimandiri:
 * the company profile + its five business units, a guest consultation form, and
 * the door into the consumer portal.
 *
 * It never queries a project or a consumer — there is no sensitive data here.
 * The consultation form reuses GuestConsultationStore verbatim (ADR-0003): the
 * session lives ENTIRELY in Redis with a sliding TTL and is never persisted to
 * the database, exactly like the guest chat API. The portal login (real
 * consumer data) stays firmly behind /portal.
 */
#[Layout('components.layouts.public')]
class Landing extends Component
{
    /** Selected unit for the consultation (only the four consultable bidang). */
    public string $consultBidang = '';

    /** The guest's first (or next) message. */
    public string $consultMessage = '';

    /** The opaque Redis session token, once a consultation has started. */
    public ?string $token = null;

    /** Messages seen so far in the live guest thread. */
    public array $messages = [];

    /** How many messages the client has already read (poll cursor). */
    public int $cursor = 0;

    /** The bidang the live session is routed to (for the thread header). */
    public ?string $sessionBidang = null;

    public ?string $error = null;

    /**
     * Start a guest consultation. Reuses GuestConsultationStore::start — a Redis
     * session, no database write (regression 1B: the ephemeral guarantee).
     */
    public function startConsultation(GuestConsultationStore $store): void
    {
        $this->reset('error');

        $data = $this->validate([
            'consultBidang' => ['required', new Enum(Bidang::class)],
            'consultMessage' => ['required', 'string', 'max:5000'],
        ]);

        $result = $store->start(Bidang::from($data['consultBidang']), $data['consultMessage']);

        $this->token = $result['token'];
        $this->sessionBidang = $result['bidang'];
        $this->messages = [$result['message']];
        $this->cursor = $result['cursor'];
        $this->reset('consultMessage');
    }

    /** Append a follow-up guest message to the live session. */
    public function sendReply(GuestConsultationStore $store): void
    {
        $this->reset('error');

        if ($this->token === null) {
            return;
        }

        $data = $this->validate(['consultMessage' => ['required', 'string', 'max:5000']]);

        try {
            $result = $store->append($this->token, $data['consultMessage']);
        } catch (GuestSessionNotFoundException) {
            $this->endedSession();

            return;
        }

        $this->messages[] = $result['message'];
        $this->cursor = $result['cursor'];
        $this->reset('consultMessage');
    }

    /**
     * Poll for new messages (Manager replies) and keep the session alive. Runs
     * only while a session exists; an expired token quietly ends the thread.
     */
    public function poll(GuestConsultationStore $store): void
    {
        if ($this->token === null) {
            return;
        }

        try {
            $result = $store->fetch($this->token, $this->cursor);
        } catch (GuestSessionNotFoundException) {
            $this->endedSession();

            return;
        }

        foreach ($result['messages'] as $message) {
            $this->messages[] = $message;
        }
        $this->cursor = $result['cursor'];
    }

    /** Reset the thread state when a session has expired (TTL lapsed). */
    private function endedSession(): void
    {
        $this->reset('token', 'messages', 'cursor', 'sessionBidang');
        $this->error = 'Sesi konsultasi telah berakhir. Silakan mulai lagi.';
    }

    public function render()
    {
        return view('livewire.public.landing', [
            'units' => $this->units(),
            'consultBidangOptions' => Bidang::cases(),
        ]);
    }

    /**
     * The five business units. CM is the parent (induk) and is NOT a consultable
     * bidang, so it carries no bidang value. A per-unit logo is shown only when a
     * file is actually present (public/images/units/{key}.(svg|png)); otherwise a
     * monogram badge is used, so the page never depends on missing assets.
     *
     * @return array<int, array{key: string, name: string, tagline: string, description: string, bidang: string|null}>
     */
    private function units(): array
    {
        return [
            [
                'key' => 'cufid',
                'name' => 'CuFID',
                'tagline' => 'Furniture',
                'description' => 'Desain dan produksi furniture custom untuk hunian, kantor, dan komersial.',
                'bidang' => Bidang::Cufid->value,
            ],
            [
                'key' => 'cc',
                'name' => 'Custom Construction',
                'tagline' => 'Konstruksi',
                'description' => 'Jasa pembangunan, renovasi, dan interior bangunan dari konsep hingga serah terima.',
                'bidang' => Bidang::Cc->value,
            ],
            [
                'key' => 'solit',
                'name' => 'SolIT',
                'tagline' => 'Teknologi Informasi',
                'description' => 'Solusi perangkat lunak, jaringan, dan sistem informasi untuk bisnis.',
                'bidang' => Bidang::Solit->value,
            ],
            [
                'key' => 'birugis',
                'name' => 'BIRU GIS',
                'tagline' => 'Survey & Pemetaan',
                'description' => 'Layanan survey, pemetaan, dan sistem informasi geografis (GIS).',
                'bidang' => Bidang::BiruGis->value,
            ],
            [
                'key' => 'cm',
                'name' => 'Cimandiri (CM)',
                'tagline' => 'Perusahaan Induk',
                'description' => 'CV. Cimandiri — induk yang menaungi seluruh unit usaha, berdiri sejak 2008 di Bogor.',
                'bidang' => null,
            ],
        ];
    }
}
