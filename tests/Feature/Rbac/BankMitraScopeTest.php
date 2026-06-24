<?php

use App\Models\Role;
use App\Models\Scopes\BankMitraScope;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * The `projects` table does not exist yet, so the skeleton is exercised at the
 * query-builder level (toSql/bindings) rather than by running the query.
 */
it('constrains a Mitra to its own projects via bank_mitra_id', function () {
    $mitra = User::factory()->create([
        'role_id' => Role::where('name', 'mitra_pembiayaan')->value('id'),
    ]);

    $builder = User::query(); // stand-in builder for the future Project model
    BankMitraScope::constrainFor($builder, $mitra);

    expect($builder->toSql())->toContain(BankMitraScope::FOREIGN_KEY)
        ->and($builder->getBindings())->toContain($mitra->getKey());
});

it('leaves non-Mitra queries untouched', function () {
    $direktur = User::factory()->create([
        'role_id' => Role::where('name', 'direktur')->value('id'),
    ]);

    $builder = User::query();
    BankMitraScope::constrainFor($builder, $direktur);

    expect($builder->toSql())->not->toContain(BankMitraScope::FOREIGN_KEY);
});

it('leaves guest (unauthenticated) queries untouched', function () {
    $builder = User::query();
    BankMitraScope::constrainFor($builder, null);

    expect($builder->toSql())->not->toContain(BankMitraScope::FOREIGN_KEY);
});
