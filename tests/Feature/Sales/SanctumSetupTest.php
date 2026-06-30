<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\HasApiTokens;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('wires Sanctum: the User is tokenable and the table exists', function () {
    $konsumen = User::factory()->create([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
    ]);

    expect(in_array(HasApiTokens::class, class_uses_recursive($konsumen), true))->toBeTrue();

    $token = $konsumen->createToken('pwa');

    expect($token->plainTextToken)->toBeString()
        ->and($konsumen->tokens()->count())->toBe(1);
});

it('registers the sanctum guard', function () {
    expect(config('auth.guards.sanctum.driver'))->toBe('sanctum');
});
