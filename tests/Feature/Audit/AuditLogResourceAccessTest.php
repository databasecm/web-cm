<?php

use App\Filament\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function userWithRole(string $roleName): User
{
    $role = Role::where('name', $roleName)->sole();

    return User::factory()->create(['role_id' => $role->id]);
}

it('exposes the audit log only to Owner and Direktur', function () {
    $allowed = ['owner', 'direktur'];
    $denied = ['manager', 'finance', 'hr', 'mitra_pembiayaan', 'mandor', 'konsumen'];

    foreach ($allowed as $roleName) {
        $this->actingAs(userWithRole($roleName));
        expect(AuditLogResource::canViewAny())->toBeTrue("{$roleName} should see the audit log");
    }

    foreach ($denied as $roleName) {
        $this->actingAs(userWithRole($roleName));
        expect(AuditLogResource::canViewAny())->toBeFalse("{$roleName} must not see the audit log");
    }
});

it('is strictly read-only', function () {
    $this->actingAs(userWithRole('owner'));
    $log = AuditLog::create([
        'action' => 'created',
        'entity' => User::class,
        'entity_id' => 1,
    ]);

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($log))->toBeFalse()
        ->and(AuditLogResource::canDelete($log))->toBeFalse()
        ->and(AuditLogResource::canDeleteAny())->toBeFalse();
});
