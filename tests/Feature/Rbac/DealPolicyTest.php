<?php

use App\Enums\Bidang;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function dealActor(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

it('lets Owner and Direktur create a customer for a deal in any bidang', function () {
    foreach (['owner', 'direktur'] as $roleName) {
        $actor = dealActor($roleName);
        expect(Gate::forUser($actor)->allows('createCustomerForDeal', Bidang::Cufid->value))->toBeTrue()
            ->and(Gate::forUser($actor)->allows('createCustomerForDeal', Bidang::BiruGis->value))->toBeTrue();
    }
});

it('confines a Manager to its own bidang', function () {
    $manager = dealActor('manager', Bidang::Cufid);

    expect(Gate::forUser($manager)->allows('createCustomerForDeal', Bidang::Cufid->value))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('createCustomerForDeal', Bidang::Cc->value))->toBeFalse();
});

it('denies everyone who does not handle consultations', function () {
    foreach (['finance', 'hr', 'mitra_pembiayaan', 'supplier', 'mandor', 'konsumen'] as $roleName) {
        $actor = dealActor($roleName, $roleName === 'mandor' ? Bidang::Cufid : null);
        expect(Gate::forUser($actor)->allows('createCustomerForDeal', Bidang::Cufid->value))
            ->toBeFalse("{$roleName} must not create customers for a deal");
    }
});

it('does not widen the general account hierarchy (UserPolicy unchanged)', function () {
    // The narrow deal gate exists, yet a Manager still has no UserPolicy reach
    // over no-bidang consumer accounts (ADR-0001).
    $manager = dealActor('manager', Bidang::Cufid);
    $konsumen = dealActor('konsumen');

    expect(Gate::forUser($manager)->allows('createCustomerForDeal', Bidang::Cufid->value))->toBeTrue()
        ->and($manager->can('view', $konsumen))->toBeFalse()
        ->and($manager->can('update', $konsumen))->toBeFalse()
        ->and($manager->can('delete', $konsumen))->toBeFalse();
});
