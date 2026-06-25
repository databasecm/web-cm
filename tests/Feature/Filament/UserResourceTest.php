<?php

use App\Enums\Bidang;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function roled(string $roleName, ?Bidang $bidang = null, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ], $attrs));
}

it('shows the account resource only to management-capable actors', function () {
    $allowed = ['owner', 'direktur', 'manager'];
    $denied = ['finance', 'hr', 'mitra_pembiayaan', 'mandor', 'konsumen'];

    foreach ($allowed as $name) {
        $this->actingAs(roled($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(UserResource::canViewAny())->toBeTrue("{$name} should see the account resource");
    }

    foreach ($denied as $name) {
        $this->actingAs(roled($name, $name === 'mandor' ? Bidang::Cufid : null));
        expect(UserResource::canViewAny())->toBeFalse("{$name} must not see the account resource")
            ->and(UserResource::canCreate())->toBeFalse();
    }
});

it('hides delete for a protected Owner and for the actor itself', function () {
    $owner = roled('owner', attrs: ['is_protected' => true]);
    $direktur = roled('direktur');
    $manager = roled('manager', Bidang::Cufid);

    $this->actingAs($direktur);

    expect(UserResource::canDelete($owner))->toBeFalse()   // §6.1 protected Owner
        ->and(UserResource::canDelete($direktur))->toBeFalse() // §6.2 self
        ->and(UserResource::canDelete($manager))->toBeTrue();  // subordinate

    // Even the Owner cannot delete itself.
    $this->actingAs($owner);
    expect(UserResource::canDelete($owner))->toBeFalse()
        ->and(UserResource::canDelete($direktur))->toBeTrue();
});

it('scopes a Manager to its own bidang for edit and delete', function () {
    $manager = roled('manager', Bidang::Cufid);
    $mandorSame = roled('mandor', Bidang::Cufid);
    $mandorOther = roled('mandor', Bidang::Cc);

    $this->actingAs($manager);

    expect(UserResource::canCreate())->toBeTrue()
        ->and(UserResource::canEdit($mandorSame))->toBeTrue()
        ->and(UserResource::canDelete($mandorSame))->toBeTrue()
        ->and(UserResource::canEdit($mandorOther))->toBeFalse()
        ->and(UserResource::canDelete($mandorOther))->toBeFalse()
        // A Manager cannot manage a peer/superior.
        ->and(UserResource::canEdit(roled('direktur')))->toBeFalse();
});

it('limits assignable roles to those strictly below the actor', function () {
    // Ordered by level, then by insertion id within a level (manager/finance/hr).
    expect(roled('owner')->assignableRoles()->pluck('name')->all())
        ->toBe(['direktur', 'manager', 'finance', 'hr', 'mitra_pembiayaan', 'supplier', 'mandor', 'konsumen']);

    expect(roled('direktur')->assignableRoles()->pluck('name')->all())
        ->not->toContain('owner')
        ->not->toContain('direktur')
        ->toContain('manager');

    // Manager (L3) may only assign L4–L6.
    expect(roled('manager', Bidang::Cufid)->assignableRoles()->pluck('name')->all())
        ->toBe(['mitra_pembiayaan', 'supplier', 'mandor', 'konsumen']);

    // No management capability => nothing to assign.
    expect(roled('finance')->assignableRoles())->toBeEmpty()
        ->and(roled('mitra_pembiayaan')->assignableRoles())->toBeEmpty();
});

it('lets an Owner create a subordinate with a hashed password and audited actor', function () {
    $owner = roled('owner');
    $this->actingAs($owner);

    $mandorRole = Role::where('name', 'mandor')->value('id');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Tukang Budi',
            'email' => 'budi@cm.test',
            'password' => 'secret-pass',
            'role_id' => $mandorRole,
            'bidang' => Bidang::Cufid->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'budi@cm.test')->sole();

    expect($created->role_id)->toBe($mandorRole)
        ->and($created->bidang)->toBe(Bidang::Cufid)
        ->and(Hash::check('secret-pass', $created->password))->toBeTrue();
});

it('rejects a Manager assigning a role above its own level', function () {
    $manager = roled('manager', Bidang::Cufid);
    $this->actingAs($manager);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Naik Pangkat',
            'email' => 'escalate@cm.test',
            'password' => 'secret-pass',
            'role_id' => Role::where('name', 'direktur')->value('id'),
        ])
        ->call('create')
        ->assertHasFormErrors(['role_id']);

    expect(User::where('email', 'escalate@cm.test')->exists())->toBeFalse();
});

it('rejects a Manager placing an account in another bidang', function () {
    $manager = roled('manager', Bidang::Cufid);
    $this->actingAs($manager);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Lintas Bidang',
            'email' => 'crossbidang@cm.test',
            'password' => 'secret-pass',
            'role_id' => Role::where('name', 'mandor')->value('id'),
            'bidang' => Bidang::Cc->value, // not the Manager's own unit
        ])
        ->call('create')
        ->assertHasFormErrors(['role_id']);

    expect(User::where('email', 'crossbidang@cm.test')->exists())->toBeFalse();
});
