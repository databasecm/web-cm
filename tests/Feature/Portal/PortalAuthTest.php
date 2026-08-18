<?php

use App\Livewire\Portal\Login;
use App\Livewire\Portal\ResetPassword;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function portalConsumer(bool $verified = true): User
{
    $factory = User::factory();

    if (! $verified) {
        $factory = $factory->unverified();
    }

    return $factory->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
}

function portalStaff(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
}

// ---------------------------------------------------------------------------
// Access separation: portal vs staff panel
// ---------------------------------------------------------------------------

it('redirects an unauthenticated visitor to the portal login', function () {
    $this->get('/portal')->assertRedirect(route('portal.login'));
});

it('lets a verified consumer reach the portal shell', function () {
    $this->actingAs(portalConsumer())
        ->get('/portal')
        ->assertOk()
        ->assertSee('Portal Konsumen');
});

it('sends an unverified consumer to the email-verification notice', function () {
    $this->actingAs(portalConsumer(verified: false))
        ->get('/portal')
        ->assertRedirect(route('portal.verification.notice'));
});

it('forbids staff (L1-5) from the consumer portal', function () {
    $this->actingAs(portalStaff())
        ->get('/portal')
        ->assertForbidden();
});

it('keeps a consumer out of the staff Filament panel (403)', function () {
    $this->actingAs(portalConsumer())
        ->get('/sistem')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Login component (web guard, consumer-only)
// ---------------------------------------------------------------------------

it('logs a verified consumer in and redirects to the dashboard', function () {
    $user = portalConsumer();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.dashboard'));

    expect(Auth::guard('web')->check())->toBeTrue()
        ->and(Auth::guard('web')->id())->toBe($user->id);
});

it('refuses a staff account a portal session and logs it back out', function () {
    $user = portalStaff();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('rejects wrong credentials', function () {
    $user = portalConsumer();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(Auth::guard('web')->check())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

it('logs a consumer out', function () {
    $this->actingAs(portalConsumer())
        ->post('/portal/logout')
        ->assertRedirect(route('portal.login'));

    expect(Auth::guard('web')->check())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Password setup / reset (also verifies the email — decision G)
// ---------------------------------------------------------------------------

it('sets a password from a reset token and verifies the email', function () {
    $user = portalConsumer(verified: false);
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', $user->email)
        ->set('password', 'new-secret-123')
        ->set('password_confirmation', 'new-secret-123')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.login'));

    $user->refresh();

    expect(Hash::check('new-secret-123', $user->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});
