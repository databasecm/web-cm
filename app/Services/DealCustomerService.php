<?php

namespace App\Services;

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Exceptions\DealConversionException;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Converts a consultation deal into a consumer account (B5, ADR-0001/0003).
 *
 * This is the ONLY path by which a Manager-driven deal mints a Konsumen (L6)
 * account, and it is deliberately narrow: it creates the account, persists the
 * consultation, copies a guest transcript exactly once, and (for guest sources)
 * drops the ephemeral Redis session. It grants the actor no ongoing rights over
 * the created account — UserPolicy is untouched, so the general account
 * hierarchy never widens.
 *
 * Authorization (who/which bidang) is the caller's responsibility via the
 * `createCustomerForDeal` gate; this service enforces the STATE invariants
 * (deal status, no existing account).
 */
class DealCustomerService
{
    public function __construct(private GuestConsultationStore $store) {}

    /**
     * Promote a live guest (Redis) session to a consumer account + persisted
     * consultation, copying its transcript, then forget the ephemeral session.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function fromGuest(string $token, array $data, User $actor): Consultation
    {
        $session = $this->store->read($token);

        if (! $session['exists']) {
            throw DealConversionException::guestSessionGone($token);
        }

        $consultation = $this->convert(
            bidang: Bidang::from($session['bidang']),
            data: $data,
            actor: $actor,
            existing: null,
            isGuest: true,
            transcript: $session['messages'],
        );

        // Promoted to the database — the ephemeral copy must not linger.
        $this->store->forget($token);

        return $consultation;
    }

    /**
     * Attach a consumer account to an existing persisted consultation that is a
     * deal but has no account yet (the login-source edge). Its messages already
     * live in the database, so nothing is copied.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function fromConsultation(Consultation $consultation, array $data, User $actor): Consultation
    {
        if ($consultation->status !== ConsultationStatus::Deal) {
            throw DealConversionException::notADeal();
        }

        if ($consultation->konsumen_id !== null) {
            throw DealConversionException::alreadyHasCustomer();
        }

        return $this->convert(
            bidang: $consultation->bidang,
            data: $data,
            actor: $actor,
            existing: $consultation,
            isGuest: (bool) $consultation->is_guest,
            transcript: [],
        );
    }

    /**
     * @param  array{name: string, email: string, phone?: string|null}  $data
     * @param  array<int, array{sender_type: string, message: string, ts?: float}>  $transcript
     */
    private function convert(
        Bidang $bidang,
        array $data,
        User $actor,
        ?Consultation $existing,
        bool $isGuest,
        array $transcript,
    ): Consultation {
        $email = $data['email'];

        if (User::where('email', $email)->exists()) {
            throw DealConversionException::accountExists($email);
        }

        $consultation = DB::transaction(function () use ($bidang, $data, $actor, $existing, $isGuest, $transcript, $email): Consultation {
            // Konsumen (L6) with no bidang. A random password is set so the row
            // is valid; the consumer sets their real one via the reset link.
            $konsumen = User::create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make(Str::password(32)),
                'role_id' => Role::where('name', 'konsumen')->value('id'),
                'bidang' => null,
                'created_by' => $actor->id,
            ]);

            $consultation = $existing ?? new Consultation;
            $consultation->forceFill([
                'konsumen_id' => $konsumen->id,
                'manager_id' => $consultation->manager_id ?? ($actor->isManager() ? $actor->id : null),
                'bidang' => $bidang,
                'status' => ConsultationStatus::Deal,
                'is_guest' => $isGuest,
            ])->save();

            // Copy the guest transcript exactly once. Only the author and message
            // text are persisted — none of the ephemeral session's other fields.
            foreach ($transcript as $message) {
                ConsultationMessage::create([
                    'consultation_id' => $consultation->id,
                    'sender_type' => $message['sender_type'],
                    'message' => $message['message'],
                ]);
            }

            return $consultation;
        });

        // Self-service password setup (ADR-0003): staff never hold the password.
        Password::sendResetLink(['email' => $email]);

        return $consultation->refresh();
    }
}
