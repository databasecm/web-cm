<?php

use App\Enums\Bidang;
use App\Filament\Pages\Auth\TwoFactorChallenge;
use App\Filament\Pages\Auth\TwoFactorSetup;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function twoFaUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

/** Enable and confirm 2FA for a user, returning the plaintext TOTP secret. */
function enableTwoFactor(User $user): string
{
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $user->refresh();

    return decrypt($user->two_factor_secret);
}

function currentOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

function dashboardUrl(): string
{
    return route('filament.sistem.pages.dashboard');
}

// ---------------------------------------------------------------------------
// Mandatory enrollment for levels 1–3
// ---------------------------------------------------------------------------

it('forces accounts that require 2FA to enroll before using the panel', function (string $role, ?Bidang $bidang) {
    $this->actingAs(twoFaUser($role, $bidang));

    $this->get(dashboardUrl())->assertRedirect(TwoFactorSetup::getUrl());
})->with([
    'Owner (L1)' => ['owner', null],
    'Direktur (L2)' => ['direktur', null],
    'Manager (L3)' => ['manager', Bidang::Cufid],
    'Finance (L3)' => ['finance', null],
    'HR (L3)' => ['hr', null],
]);

it('does not force 2FA on levels 4–6', function (string $role, ?Bidang $bidang) {
    $this->actingAs(twoFaUser($role, $bidang));

    $response = $this->get(dashboardUrl());

    $response->assertOk();
    expect($response->headers->get('Location'))->not->toBe(TwoFactorSetup::getUrl());
})->with([
    'Mitra Pembiayaan (L4)' => ['mitra_pembiayaan', null],
    'Supplier (L4)' => ['supplier', null],
    'Mandor (L5)' => ['mandor', Bidang::Cufid],
    'Konsumen (L6)' => ['konsumen', null],
]);

// ---------------------------------------------------------------------------
// Challenge once 2FA is enabled
// ---------------------------------------------------------------------------

it('challenges an enrolled account on a fresh session', function () {
    $user = twoFaUser('owner');
    enableTwoFactor($user);

    $this->actingAs($user);

    $this->get(dashboardUrl())->assertRedirect(TwoFactorChallenge::getUrl());
});

it('grants access once the session has cleared the challenge', function () {
    $user = twoFaUser('owner');
    enableTwoFactor($user);

    $this->actingAs($user);
    session()->put('auth.two_factor_passed', true);

    $this->get(dashboardUrl())->assertOk();
});

it('passes the challenge with a valid TOTP code', function () {
    $user = twoFaUser('direktur');
    $secret = enableTwoFactor($user);
    $this->actingAs($user);

    Livewire::test(TwoFactorChallenge::class)
        ->fillForm(['code' => currentOtp($secret)])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(session('auth.two_factor_passed'))->toBeTrue();
});

it('rejects an invalid challenge code', function () {
    $user = twoFaUser('direktur');
    enableTwoFactor($user);
    $this->actingAs($user);

    Livewire::test(TwoFactorChallenge::class)
        ->fillForm(['code' => '000000'])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    expect(session('auth.two_factor_passed'))->toBeNull();
});

it('passes the challenge with a recovery code and consumes it', function () {
    $user = twoFaUser('owner');
    enableTwoFactor($user);
    $this->actingAs($user);

    $recoveryCode = $user->recoveryCodes()[0];

    Livewire::test(TwoFactorChallenge::class)
        ->fillForm(['code' => $recoveryCode])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(session('auth.two_factor_passed'))->toBeTrue()
        // the used recovery code is replaced, so it no longer validates
        ->and($user->refresh()->recoveryCodes())->not->toContain($recoveryCode);
});

// ---------------------------------------------------------------------------
// Enrollment flow + encrypted storage
// ---------------------------------------------------------------------------

it('enrolls via the setup page, confirming a code and revealing recovery codes', function () {
    $user = twoFaUser('manager', Bidang::Cufid);
    $this->actingAs($user);

    $component = Livewire::test(TwoFactorSetup::class); // mount generates the pending secret

    $secret = decrypt($user->refresh()->two_factor_secret);

    $component
        ->fillForm(['code' => currentOtp($secret)])
        ->call('confirm')
        ->assertHasNoFormErrors()
        ->assertSet('confirmed', true);

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($component->get('recoveryCodes'))->not->toBeEmpty()
        ->and(session('auth.two_factor_passed'))->toBeTrue();
});

it('stores the 2FA secret encrypted and never exposes it', function () {
    $user = twoFaUser('owner');
    $secret = enableTwoFactor($user);

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($raw)->not->toBe($secret)        // stored ciphertext != plaintext
        ->and(decrypt($raw))->toBe($secret) // but decryptable back to the secret
        ->and($user->toArray())->not->toHaveKey('two_factor_secret')        // hidden from serialization
        ->and($user->toArray())->not->toHaveKey('two_factor_recovery_codes');

    // And the plaintext secret must never appear in the audit trail.
    $audit = AuditLog::query()->get()->map->toJson()->implode('');
    expect($audit)->not->toContain($secret);
});
