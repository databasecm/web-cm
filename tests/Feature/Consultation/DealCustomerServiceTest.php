<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Exceptions\DealConversionException;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Role;
use App\Models\User;
use App\Services\DealCustomerService;
use App\Services\GuestConsultationStore;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Redis::connection()->flushdb();
    Notification::fake();
    $this->store = new GuestConsultationStore;
    $this->service = new DealCustomerService($this->store);
});

function manager(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

function customerData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Budi Konsumen',
        'email' => 'budi@example.test',
        'phone' => '0812000111',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Guest source — happy path
// ---------------------------------------------------------------------------

it('promotes a guest session to a consumer account, persisted consultation and copied transcript', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo, mau pesan lemari')['token'];
    $this->store->appendManagerReply($token, 'Baik, boleh dijelaskan ukurannya?');

    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $consultation = $this->service->fromGuest($token, customerData(), $manager);

    // Konsumen L6 account, no bidang, created_by the manager.
    $konsumen = User::where('email', 'budi@example.test')->sole();
    expect($konsumen->level())->toBe(Role::LEVEL_KONSUMEN)
        ->and($konsumen->bidang)->toBeNull()
        ->and($konsumen->phone)->toBe('0812000111')
        ->and($konsumen->created_by)->toBe($manager->id);

    // Persisted consultation, status=deal, is_guest=true, claimed by the manager.
    expect($consultation->konsumen_id)->toBe($konsumen->id)
        ->and($consultation->manager_id)->toBe($manager->id)
        ->and($consultation->status)->toBe(ConsultationStatus::Deal)
        ->and($consultation->is_guest)->toBeTrue()
        ->and($consultation->bidang)->toBe(Bidang::Cufid);

    // Transcript copied (in order), exactly the two messages.
    $messages = $consultation->messages()->orderBy('id')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->message)->toBe('Halo, mau pesan lemari')
        ->and($messages[1]->sender_type->value)->toBe('manager');

    // Self-service invite sent.
    Notification::assertSentTo($konsumen, ResetPassword::class);

    // Redis session forgotten after promotion.
    expect($this->store->exists($token))->toBeFalse()
        ->and($this->store->liveSessions(Bidang::Cufid))->toBeEmpty();
});

it('does not double-copy the transcript when promoting a guest session', function () {
    $token = $this->store->start(Bidang::Cufid, 'Satu')['token'];
    $this->store->append($token, 'Dua');

    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $consultation = $this->service->fromGuest($token, customerData(), $manager);

    expect(ConsultationMessage::where('consultation_id', $consultation->id)->count())->toBe(2)
        // the source is gone, so it cannot be promoted (and copied) a second time
        ->and(fn () => $this->service->fromGuest($token, customerData(['email' => 'x@example.test']), $manager))
        ->toThrow(DealConversionException::class);
});

// ---------------------------------------------------------------------------
// Account creation is audited (§6.6), with secrets redacted
// ---------------------------------------------------------------------------

it('writes an audit row for the created account without leaking secrets', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $this->service->fromGuest($token, customerData(), $manager);
    $konsumen = User::where('email', 'budi@example.test')->sole();

    $audit = AuditLog::where('entity', User::class)
        ->where('entity_id', $konsumen->id)
        ->where('action', 'created')
        ->sole();

    expect($audit->user_id)->toBe($manager->id)
        ->and($audit->after['password'])->toBe('[redacted]')
        ->and($audit->after)->not->toHaveKey('two_factor_secret')
        ->and($audit->after['email'])->toBe('budi@example.test');
});

it('persists only author and text in the copied transcript — no sensitive fields', function () {
    $token = $this->store->start(Bidang::Cufid, 'Rahasia? tidak')['token'];
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $consultation = $this->service->fromGuest($token, customerData(), $manager);
    $message = $consultation->messages()->sole();

    // The persisted columns are exactly the message essentials — the ephemeral
    // token, scores or any other Redis-side field never reach the database.
    expect(array_keys($message->getAttributes()))
        ->toEqualCanonicalizing(['id', 'consultation_id', 'sender_type', 'message', 'attachment', 'created_at', 'updated_at'])
        ->and($message->attachment)->toBeNull();
});

// ---------------------------------------------------------------------------
// State invariant — only valid when no account exists yet
// ---------------------------------------------------------------------------

it('refuses to promote a guest when the email already has an account', function () {
    User::factory()->create(['email' => 'budi@example.test']);
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    expect(fn () => $this->service->fromGuest($token, customerData(), $manager))
        ->toThrow(DealConversionException::class);

    // Nothing persisted, and the guest session is left intact.
    expect(Consultation::count())->toBe(0)
        ->and($this->store->exists($token))->toBeTrue();
});

it('refuses a vanished guest session', function () {
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    expect(fn () => $this->service->fromGuest('ghost', customerData(), $manager))
        ->toThrow(DealConversionException::class);
});

// ---------------------------------------------------------------------------
// Consultation source (login edge) — only when status=deal & no account
// ---------------------------------------------------------------------------

it('attaches an account to a deal consultation that has none', function () {
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $consultation = Consultation::factory()
        ->inBidang(Bidang::Cufid)
        ->claimedBy($manager)
        ->status(ConsultationStatus::Deal)
        ->create(['konsumen_id' => null]);

    $result = $this->service->fromConsultation($consultation, customerData(), $manager);

    $konsumen = User::where('email', 'budi@example.test')->sole();
    expect($result->konsumen_id)->toBe($konsumen->id)
        ->and($konsumen->level())->toBe(Role::LEVEL_KONSUMEN);
});

it('refuses a consultation that is not a deal or already has an account', function () {
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $open = Consultation::factory()->inBidang(Bidang::Cufid)->status(ConsultationStatus::Open)->create();
    expect(fn () => $this->service->fromConsultation($open, customerData(), $manager))
        ->toThrow(DealConversionException::class);

    $taken = Consultation::factory()
        ->inBidang(Bidang::Cufid)
        ->status(ConsultationStatus::Deal)
        ->ownedBy(User::factory()->create(['role_id' => Role::where('name', 'konsumen')->value('id')]))
        ->create();
    expect(fn () => $this->service->fromConsultation($taken, customerData(['email' => 'other@example.test']), $manager))
        ->toThrow(DealConversionException::class);
});

// ---------------------------------------------------------------------------
// HARD INVARIANT — the deal action never widens the account hierarchy
// ---------------------------------------------------------------------------

it('grants the Manager no ongoing rights over the account it created (ADR-0001/0003)', function () {
    $token = $this->store->start(Bidang::Cufid, 'Halo')['token'];
    $manager = manager(Bidang::Cufid);
    $this->actingAs($manager);

    $this->service->fromGuest($token, customerData(), $manager);
    $konsumen = User::where('email', 'budi@example.test')->sole();

    // UserPolicy is untouched: a Manager still cannot manage a no-bidang consumer
    // — including the very one it just created.
    expect($manager->can('view', $konsumen))->toBeFalse()
        ->and($manager->can('update', $konsumen))->toBeFalse()
        ->and($manager->can('delete', $konsumen))->toBeFalse();
});
