<?php

use App\Enums\Bidang;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function panelUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// canAccessPanel rule — internal staff + Mitra + Mandor allowed, Konsumen denied
// ---------------------------------------------------------------------------

it('allows levels 1–5 onto the panel and denies Konsumen', function (string $role, ?Bidang $bidang, bool $allowed) {
    $panel = Filament::getPanel('sistem');

    expect(panelUser($role, $bidang)->canAccessPanel($panel))->toBe($allowed);
})->with([
    'Owner (L1)' => ['owner', null, true],
    'Direktur (L2)' => ['direktur', null, true],
    'Manager (L3)' => ['manager', Bidang::Cufid, true],
    'Finance (L3)' => ['finance', null, true],
    'HR (L3)' => ['hr', null, true],
    'Mitra Pembiayaan (L4)' => ['mitra_pembiayaan', null, true],
    'Supplier (L4)' => ['supplier', null, true],
    'Mandor (L5)' => ['mandor', Bidang::Cufid, true],
    'Konsumen (L6)' => ['konsumen', null, false],
]);

it('denies an account without a role', function () {
    $user = User::factory()->create(['role_id' => null]);

    expect($user->canAccessPanel(Filament::getPanel('sistem')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Server-side enforcement at the HTTP layer (not just hidden navigation)
// ---------------------------------------------------------------------------

it('forbids a Konsumen from reaching the panel with a 403', function () {
    $this->actingAs(panelUser('konsumen'));

    $this->get(route('filament.sistem.pages.dashboard'))->assertForbidden();
});

it('lets Mitra and Mandor (L4–L5, 2FA optional) reach the panel', function (string $role, ?Bidang $bidang) {
    $this->actingAs(panelUser($role, $bidang));

    $this->get(route('filament.sistem.pages.dashboard'))->assertOk();
})->with([
    'Mitra Pembiayaan (L4)' => ['mitra_pembiayaan', null],
    'Mandor (L5)' => ['mandor', Bidang::Cufid],
]);

it('admits an internal L1–L3 account past the access check (then to 2FA setup)', function () {
    $this->actingAs(panelUser('direktur'));

    // Passes canAccessPanel (not 403); the 2FA middleware then forces enrollment.
    $response = $this->get(route('filament.sistem.pages.dashboard'));

    $response->assertRedirect();
    expect($response->isForbidden())->toBeFalse();
});
