<?php

use App\Enums\FinancingDocumentStatus;
use App\Enums\FinancingStatus;
use App\Enums\TransactionCategory;
use App\Filament\Resources\FinancingResource;
use App\Filament\Resources\FinancingResource\Pages\ListFinancings;
use App\Filament\Resources\FinancingResource\Pages\ViewFinancing;
use App\Filament\Resources\FinancingResource\RelationManagers\DocumentsRelationManager;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function bankUser(string $role = 'mitra_pembiayaan'): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
}

// ---------------------------------------------------------------------------
// Visibility — only a financing bank
// ---------------------------------------------------------------------------

it('shows the financing portal only to a Mitra Pembiayaan', function () {
    $this->actingAs(bankUser());
    expect(FinancingResource::canViewAny())->toBeTrue();

    foreach (['supplier', 'owner', 'direktur', 'manager', 'finance', 'konsumen'] as $role) {
        $this->actingAs(bankUser($role));
        expect(FinancingResource::canViewAny())->toBeFalse("{$role} must not see the financing portal");
    }
});

// ---------------------------------------------------------------------------
// Scope — the bank sees only its own applications
// ---------------------------------------------------------------------------

it('lists only the applications of the signed-in bank', function () {
    $me = bankUser();
    $mine = Financing::factory()->forBank($me)->create();
    $theirs = Financing::factory()->forBank(bankUser())->create();

    $this->actingAs($me);

    Livewire::test(ListFinancings::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    // Another bank's application is not reachable (scoped out → 404).
    $this->get(FinancingResource::getUrl('view', ['record' => $mine]))->assertOk();
    $this->get(FinancingResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Lifecycle actions drive the services and log the trail
// ---------------------------------------------------------------------------

it('approves then disburses through the view page, posting investor income once', function () {
    $me = bankUser();
    $financing = Financing::factory()->forBank($me)->status(FinancingStatus::Interview)
        ->create(['amount' => '90000000.00']);

    $this->actingAs($me);

    Livewire::test(ViewFinancing::class, ['record' => $financing->getKey()])
        ->callAction('setujui');
    expect($financing->fresh()->status)->toBe(FinancingStatus::Approved);

    Livewire::test(ViewFinancing::class, ['record' => $financing->getKey()])
        ->callAction('cairkan');

    $financing->refresh();
    expect($financing->status)->toBe(FinancingStatus::Disbursed)
        ->and($financing->statusLogs()->count())->toBe(2); // approved + disbursed

    $income = Transaction::forFinancings()->where('reference_id', $financing->id)->get();
    expect($income)->toHaveCount(1)
        ->and($income->first()->category)->toBe(TransactionCategory::Investor)
        ->and($income->first()->amount)->toBe('90000000.00');
});

it('lets the bank accept a pending document through the relation manager', function () {
    $me = bankUser();
    $financing = Financing::factory()->forBank($me)->create();
    $doc = FinancingDocument::factory()->forFinancing($financing)->create();

    $this->actingAs($me);

    Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $financing,
        'pageClass' => ViewFinancing::class,
    ])->callTableAction('terima', $doc);

    expect($doc->refresh()->status)->toBe(FinancingDocumentStatus::Accepted)
        ->and((int) $doc->reviewed_by)->toBe($me->id);
});

// ---------------------------------------------------------------------------
// §6.5 — the portal never grants project write access
// ---------------------------------------------------------------------------

it('keeps the bank read-only on projects from the portal', function () {
    $me = bankUser();
    $financing = Financing::factory()->forBank($me)->create();

    expect($me->can('manageLifecycle', $financing))->toBeTrue()
        ->and($me->can('update', $financing->project))->toBeFalse()
        ->and($me->can('delete', $financing->project))->toBeFalse();
});
