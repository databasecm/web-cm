<?php

use App\Enums\Bidang;
use App\Filament\Pages\GuestConsultations;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Role;
use App\Models\User;
use App\Services\GuestConsultationStore;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Redis::connection()->flushdb();
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
    $this->store = new GuestConsultationStore;
});

function actorWith(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------

it('admits only consultation-handling staff', function () {
    foreach (['owner', 'direktur', 'manager'] as $name) {
        $this->actingAs(actorWith($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(GuestConsultations::canAccess())->toBeTrue("{$name} should access");
    }

    foreach (['finance', 'hr', 'mitra_pembiayaan', 'supplier', 'mandor'] as $name) {
        $this->actingAs(actorWith($name, $name === 'mandor' ? Bidang::Cufid : null));
        expect(GuestConsultations::canAccess())->toBeFalse("{$name} must not access");
    }
});

// ---------------------------------------------------------------------------
// Bidang scope for guest sessions
// ---------------------------------------------------------------------------

it('shows a Manager only guest sessions in its own bidang', function () {
    $cufid = $this->store->start(Bidang::Cufid, 'Halo CuFID')['token'];
    $cc = $this->store->start(Bidang::Cc, 'Halo CC')['token'];

    $this->actingAs(actorWith('manager', Bidang::Cufid));

    $tokens = collect(Livewire::test(GuestConsultations::class)->instance()->sessions())
        ->pluck('token')->all();

    expect($tokens)->toContain($cufid)->not->toContain($cc);
});

it('lets Owner and Direktur see guest sessions across every bidang', function () {
    $cufid = $this->store->start(Bidang::Cufid, 'A')['token'];
    $cc = $this->store->start(Bidang::Cc, 'B')['token'];

    $this->actingAs(actorWith('direktur'));

    $tokens = collect(Livewire::test(GuestConsultations::class)->instance()->sessions())
        ->pluck('token')->all();

    expect($tokens)->toContain($cufid)->toContain($cc);
});

it('refuses a Manager opening a guest session from another bidang', function () {
    $cc = $this->store->start(Bidang::Cc, 'Halo CC')['token'];

    $this->actingAs(actorWith('manager', Bidang::Cufid));

    Livewire::test(GuestConsultations::class)
        ->call('openSession', $cc)
        ->assertSet('activeToken', null);
});

// ---------------------------------------------------------------------------
// Reply + claim-on-respond (consistent with the persisted-thread B2 behaviour)
// ---------------------------------------------------------------------------

it('appends a manager reply into Redis and claims the session on first response', function () {
    $token = $this->store->start(Bidang::Cufid, 'Mau tanya')['token'];
    $manager = actorWith('manager', Bidang::Cufid);
    $this->actingAs($manager);

    Livewire::test(GuestConsultations::class)
        ->call('openSession', $token)
        ->assertSet('activeToken', $token)
        ->set('reply', 'Halo, silakan.')
        ->call('send')
        ->assertSet('reply', '');

    $read = $this->store->read($token);
    expect($read['messages'])->toHaveCount(2)
        ->and($read['messages'][1]['sender_type'])->toBe('manager')
        ->and($read['messages'][1]['message'])->toBe('Halo, silakan.')
        // claim-on-respond: the first responding Manager is now assigned
        ->and($read['manager_id'])->toBe($manager->id);
});

it('does not claim when an overseer (Direktur) replies', function () {
    $token = $this->store->start(Bidang::Cufid, 'Mau tanya')['token'];
    $this->actingAs(actorWith('direktur'));

    Livewire::test(GuestConsultations::class)
        ->call('openSession', $token)
        ->set('reply', 'Saya pantau saja.')
        ->call('send');

    $read = $this->store->read($token);
    expect($read['messages'])->toHaveCount(2)
        ->and($read['messages'][1]['sender_type'])->toBe('manager')
        ->and($read['manager_id'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Zero-DB invariant across the manager reply flow
// ---------------------------------------------------------------------------

it('writes nothing to the database across the manager guest-reply flow', function () {
    $token = $this->store->start(Bidang::Cufid, 'Mau tanya')['token'];
    $this->actingAs(actorWith('manager', Bidang::Cufid));

    Livewire::test(GuestConsultations::class)
        ->call('openSession', $token)
        ->set('reply', 'Balasan tamu')
        ->call('send');

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationMessage::count())->toBe(0);
});
