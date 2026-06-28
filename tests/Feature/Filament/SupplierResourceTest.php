<?php

use App\Enums\Bidang;
use App\Filament\Resources\SupplierResource;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Models\Role;
use App\Models\Supplier;
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

function supplierUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

it('shows suppliers to every internal account, hidden from Mitra/Konsumen', function () {
    foreach (['owner', 'direktur', 'manager', 'finance', 'hr', 'mandor'] as $name) {
        $this->actingAs(supplierUser($name, in_array($name, ['manager', 'mandor'], true) ? Bidang::Cufid : null));
        expect(SupplierResource::canViewAny())->toBeTrue("{$name} should view");
    }

    foreach (['mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $this->actingAs(supplierUser($name));
        expect(SupplierResource::canViewAny())->toBeFalse("{$name} must not view");
    }
});

it('lets only Owner, Direktur and Manager manage suppliers', function () {
    $supplier = Supplier::factory()->create();

    foreach (['owner', 'direktur', 'manager'] as $name) {
        $this->actingAs(supplierUser($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(SupplierResource::canCreate())->toBeTrue("{$name} create")
            ->and(SupplierResource::canEdit($supplier))->toBeTrue("{$name} edit");
    }

    foreach (['finance', 'hr', 'mandor'] as $name) {
        $this->actingAs(supplierUser($name, $name === 'mandor' ? Bidang::Cufid : null));
        expect(SupplierResource::canCreate())->toBeFalse("{$name} create")
            ->and(SupplierResource::canEdit($supplier))->toBeFalse("{$name} edit");
    }
});

it('creates a supplier', function () {
    $this->actingAs(supplierUser('owner'));

    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'company_name' => 'TB. Baru Jadi',
            'phone' => '0812000',
            'address' => 'Jl. Raya Nasional No. 71',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Supplier::where('company_name', 'TB. Baru Jadi')->exists())->toBeTrue();
});
