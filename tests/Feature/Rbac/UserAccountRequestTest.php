<?php

use App\Enums\Bidang;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function roleId(string $name): int
{
    return Role::where('name', $name)->value('id');
}

function person(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => roleId($roleName),
        'bidang' => $bidang,
    ]);
}

/** Build a StoreUserRequest acting as $actor with the given payload. */
function storeRequest(User $actor, array $data): StoreUserRequest
{
    $request = StoreUserRequest::create('/sistem/users', 'POST', $data);
    $request->setContainer(app());
    $request->setUserResolver(fn () => $actor);

    return $request;
}

/** Build an UpdateUserRequest acting as $actor against $target. */
function updateRequest(User $actor, User $target, array $data): UpdateUserRequest
{
    $request = UpdateUserRequest::create("/sistem/users/{$target->id}", 'PUT', $data);
    $request->setContainer(app());
    $request->setUserResolver(fn () => $actor);

    $route = new Route('PUT', '/sistem/users/{user}', []);
    $route->bind($request);
    $route->setParameter('user', $target);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

// ---------------------------------------------------------------------------
// Store authorization (forbidden → false → 403)
// ---------------------------------------------------------------------------

it('authorizes an Owner creating a manager account', function () {
    $request = storeRequest(person('owner'), [
        'name' => 'New Manager',
        'email' => 'mgr@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('manager'),
        'bidang' => Bidang::Cufid->value,
    ]);

    expect($request->authorize())->toBeTrue();
});

it('lets a Manager create a Mandor within its own bidang', function () {
    $request = storeRequest(person('manager', Bidang::Cufid), [
        'name' => 'Mandor Cufid',
        'email' => 'mandor@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('mandor'),
        'bidang' => Bidang::Cufid->value,
    ]);

    expect($request->authorize())->toBeTrue();
});

it('forbids a Manager creating an account in another bidang', function () {
    $request = storeRequest(person('manager', Bidang::Cufid), [
        'name' => 'Mandor CC',
        'email' => 'mandorcc@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('mandor'),
        'bidang' => Bidang::Cc->value,
    ]);

    expect($request->authorize())->toBeFalse();
});

it('forbids a Manager assigning a role above itself', function () {
    $request = storeRequest(person('manager', Bidang::Cufid), [
        'name' => 'Wannabe Direktur',
        'email' => 'up@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('direktur'),
    ]);

    expect($request->authorize())->toBeFalse();
});

it('forbids a Mitra (L4) from creating any account', function () {
    $request = storeRequest(person('mitra_pembiayaan'), [
        'name' => 'Konsumen',
        'email' => 'k@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('konsumen'),
    ]);

    expect($request->authorize())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Store validation (bidang shape + required fields → 422)
// ---------------------------------------------------------------------------

it('requires a bidang when creating a manager or mandor', function () {
    $request = storeRequest(person('owner'), [
        'name' => 'No Bidang Manager',
        'email' => 'nb@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('manager'),
    ]);

    $validator = Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bidang'))->toBeTrue();
});

it('forbids a bidang on roles that must not carry one', function () {
    $request = storeRequest(person('owner'), [
        'name' => 'Finance With Bidang',
        'email' => 'fb@cm.test',
        'password' => 'secret-pass',
        'role_id' => roleId('finance'),
        'bidang' => Bidang::Cufid->value,
    ]);

    $validator = Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bidang'))->toBeTrue();
});

it('rejects missing required fields on create', function () {
    $request = storeRequest(person('owner'), [
        'role_id' => roleId('direktur'),
    ]);

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue()
        ->and($validator->errors()->has('email'))->toBeTrue()
        ->and($validator->errors()->has('password'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Update authorization
// ---------------------------------------------------------------------------

it('authorizes an Owner updating a direktur', function () {
    $request = updateRequest(person('owner'), person('direktur'), ['name' => 'Renamed']);

    expect($request->authorize())->toBeTrue();
});

it('forbids a Manager updating an account in another bidang', function () {
    $actor = person('manager', Bidang::Cufid);
    $target = person('mandor', Bidang::Cc);

    $request = updateRequest($actor, $target, ['name' => 'Renamed']);

    expect($request->authorize())->toBeFalse();
});

it('forbids updating a protected Owner', function () {
    $owner = User::factory()->create([
        'role_id' => roleId('owner'),
        'is_protected' => true,
    ]);

    $request = updateRequest(person('direktur'), $owner, ['name' => 'Hijack']);

    expect($request->authorize())->toBeFalse();
});
