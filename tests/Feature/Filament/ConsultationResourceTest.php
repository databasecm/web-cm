<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Enums\SenderType;
use App\Filament\Resources\ConsultationResource;
use App\Filament\Resources\ConsultationResource\Pages\ListConsultations;
use App\Filament\Resources\ConsultationResource\Pages\ViewConsultation;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function staff(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

function konsumen(): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
    ]);
}

// ---------------------------------------------------------------------------
// Resource visibility
// ---------------------------------------------------------------------------

it('shows the consultation inbox to triage staff only', function () {
    foreach (['owner', 'direktur', 'manager'] as $name) {
        $this->actingAs(staff($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(ConsultationResource::canViewAny())->toBeTrue("{$name} should see the inbox");
    }

    foreach (['finance', 'hr', 'mitra_pembiayaan', 'supplier', 'mandor'] as $name) {
        $this->actingAs(staff($name, $name === 'mandor' ? Bidang::Cufid : null));
        expect(ConsultationResource::canViewAny())->toBeFalse("{$name} must not see the inbox");
    }
});

it('never offers thread creation from the staff inbox', function () {
    $this->actingAs(staff('manager', Bidang::Cufid));
    expect(ConsultationResource::canCreate())->toBeFalse();
});

// ---------------------------------------------------------------------------
// §6.4 — list scoping by bidang
// ---------------------------------------------------------------------------

it('shows a Manager only consultations in its own bidang', function () {
    $cufid = Consultation::factory()->inBidang(Bidang::Cufid)->ownedBy(konsumen())->create();
    $cc = Consultation::factory()->inBidang(Bidang::Cc)->ownedBy(konsumen())->create();

    $this->actingAs(staff('manager', Bidang::Cufid));

    Livewire::test(ListConsultations::class)
        ->assertCanSeeTableRecords([$cufid])
        ->assertCanNotSeeTableRecords([$cc]);
});

it('shows Owner and Direktur consultations across every bidang', function () {
    $cufid = Consultation::factory()->inBidang(Bidang::Cufid)->ownedBy(konsumen())->create();
    $cc = Consultation::factory()->inBidang(Bidang::Cc)->ownedBy(konsumen())->create();
    $gis = Consultation::factory()->inBidang(Bidang::BiruGis)->ownedBy(konsumen())->create();

    foreach (['owner', 'direktur'] as $name) {
        $this->actingAs(staff($name));
        Livewire::test(ListConsultations::class)
            ->assertCanSeeTableRecords([$cufid, $cc, $gis]);
    }
});

it('hides a cross-bidang thread from a Manager (scoped out, not found)', function () {
    $cc = Consultation::factory()->inBidang(Bidang::Cc)->ownedBy(konsumen())->create();

    $this->actingAs(staff('manager', Bidang::Cufid));

    // The bidang scope removes the row from the query entirely, so opening it
    // resolves to nothing (404) rather than leaking its existence.
    expect(fn () => Livewire::test(ViewConsultation::class, ['record' => $cc->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

// ---------------------------------------------------------------------------
// Reply + claim
// ---------------------------------------------------------------------------

it('appends a manager reply and claims an unclaimed thread', function () {
    $thread = Consultation::factory()->inBidang(Bidang::Cufid)->ownedBy(konsumen())->create();
    $manager = staff('manager', Bidang::Cufid);
    $this->actingAs($manager);

    Livewire::test(ViewConsultation::class, ['record' => $thread->getKey()])
        ->callAction('balas', data: ['message' => 'Halo, ada yang bisa kami bantu?'])
        ->assertHasNoActionErrors();

    $thread->refresh();
    $message = $thread->messages()->sole();

    expect($message->sender_type)->toBe(SenderType::Manager)
        ->and($message->message)->toBe('Halo, ada yang bisa kami bantu?')
        // claim model: the responding Manager is now assigned (ADR-0003)
        ->and($thread->manager_id)->toBe($manager->id);
});

it('does not claim the thread when an overseer (Direktur) replies', function () {
    $thread = Consultation::factory()->inBidang(Bidang::Cufid)->ownedBy(konsumen())->create();
    $this->actingAs(staff('direktur'));

    Livewire::test(ViewConsultation::class, ['record' => $thread->getKey()])
        ->callAction('balas', data: ['message' => 'Mengawasi saja.'])
        ->assertHasNoActionErrors();

    expect($thread->refresh()->manager_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Status transitions open → deal → closed
// ---------------------------------------------------------------------------

it('transitions a thread open → deal → closed', function () {
    $thread = Consultation::factory()->inBidang(Bidang::Cufid)->ownedBy(konsumen())->create();
    $this->actingAs(staff('manager', Bidang::Cufid));

    Livewire::test(ViewConsultation::class, ['record' => $thread->getKey()])
        ->callAction('tandaiDeal');
    expect($thread->refresh()->status)->toBe(ConsultationStatus::Deal);

    Livewire::test(ViewConsultation::class, ['record' => $thread->getKey()])
        ->callAction('tutup');
    expect($thread->refresh()->status)->toBe(ConsultationStatus::Closed);
});

// ---------------------------------------------------------------------------
// Closed = read-only
// ---------------------------------------------------------------------------

it('makes a closed thread read-only: no reply or status actions', function () {
    $thread = Consultation::factory()
        ->inBidang(Bidang::Cufid)
        ->ownedBy(konsumen())
        ->status(ConsultationStatus::Closed)
        ->create();

    $this->actingAs(staff('manager', Bidang::Cufid));

    Livewire::test(ViewConsultation::class, ['record' => $thread->getKey()])
        ->assertActionHidden('balas')
        ->assertActionHidden('tandaiDeal')
        ->assertActionHidden('tutup');
});

it('refuses a reply to a closed thread even if forced', function () {
    $thread = Consultation::factory()
        ->inBidang(Bidang::Cufid)
        ->ownedBy(konsumen())
        ->status(ConsultationStatus::Closed)
        ->create();

    $this->actingAs(staff('manager', Bidang::Cufid));

    expect(auth()->user()->can('respond', $thread))->toBeFalse();
    expect(ConsultationMessage::where('consultation_id', $thread->id)->count())->toBe(0);
});
