<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Filament\Pages\GuestConsultations;
use App\Filament\Resources\ConsultationResource\Pages\ViewConsultation;
use App\Models\Consultation;
use App\Models\Role;
use App\Models\User;
use App\Services\GuestConsultationStore;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Redis::connection()->flushdb();
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
    $this->store = new GuestConsultationStore;
});

function mgr(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

function konsumenUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Guest inbox — Deal action promotes the open session
// ---------------------------------------------------------------------------

it('converts the open guest session to a consumer account from the inbox', function () {
    $token = $this->store->start(Bidang::Cufid, 'Mau pesan')['token'];
    $manager = mgr(Bidang::Cufid);
    $this->actingAs($manager);

    Livewire::test(GuestConsultations::class)
        ->call('openSession', $token)
        ->callAction('deal', data: [
            'name' => 'Citra Konsumen',
            'phone' => '0813999',
            'email' => 'citra@example.test',
            'consent' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertSet('activeToken', null);

    $konsumen = User::where('email', 'citra@example.test')->sole();
    expect($konsumen->level())->toBe(Role::LEVEL_KONSUMEN);

    $consultation = Consultation::where('konsumen_id', $konsumen->id)->sole();
    expect($consultation->status)->toBe(ConsultationStatus::Deal)
        ->and($consultation->is_guest)->toBeTrue()
        ->and($consultation->messages()->count())->toBe(1);

    // Session promoted out of Redis.
    expect($this->store->exists($token))->toBeFalse();
});

it('requires consent before converting a guest session', function () {
    $token = $this->store->start(Bidang::Cufid, 'Mau pesan')['token'];
    $this->actingAs(mgr(Bidang::Cufid));

    Livewire::test(GuestConsultations::class)
        ->call('openSession', $token)
        ->callAction('deal', data: [
            'name' => 'Tanpa Setuju',
            'email' => 'no-consent@example.test',
            'consent' => false,
        ])
        ->assertHasActionErrors(['consent']);

    expect(User::where('email', 'no-consent@example.test')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// ViewConsultation — action only for a deal without an account
// ---------------------------------------------------------------------------

it('offers Buat Akun Konsumen only for a deal consultation without an account', function () {
    $manager = mgr(Bidang::Cufid);
    $this->actingAs($manager);

    $dealNoAccount = Consultation::factory()
        ->inBidang(Bidang::Cufid)->claimedBy($manager)
        ->status(ConsultationStatus::Deal)->create(['konsumen_id' => null]);

    Livewire::test(ViewConsultation::class, ['record' => $dealNoAccount->getKey()])
        ->assertActionVisible('buatAkunKonsumen');

    // Open thread → hidden.
    $open = Consultation::factory()
        ->inBidang(Bidang::Cufid)->status(ConsultationStatus::Open)->create();
    Livewire::test(ViewConsultation::class, ['record' => $open->getKey()])
        ->assertActionHidden('buatAkunKonsumen');

    // Deal that already has an account → hidden.
    $taken = Consultation::factory()
        ->inBidang(Bidang::Cufid)->claimedBy($manager)
        ->status(ConsultationStatus::Deal)->ownedBy(konsumenUser())->create();
    Livewire::test(ViewConsultation::class, ['record' => $taken->getKey()])
        ->assertActionHidden('buatAkunKonsumen');
});

it('creates the account from a deal consultation via the action', function () {
    $manager = mgr(Bidang::Cufid);
    $this->actingAs($manager);

    $consultation = Consultation::factory()
        ->inBidang(Bidang::Cufid)->claimedBy($manager)
        ->status(ConsultationStatus::Deal)->create(['konsumen_id' => null]);

    Livewire::test(ViewConsultation::class, ['record' => $consultation->getKey()])
        ->callAction('buatAkunKonsumen', data: [
            'name' => 'Dewi Konsumen',
            'phone' => '0814222',
            'email' => 'dewi@example.test',
        ])
        ->assertHasNoActionErrors();

    $konsumen = User::where('email', 'dewi@example.test')->sole();
    expect($consultation->refresh()->konsumen_id)->toBe($konsumen->id);
});
