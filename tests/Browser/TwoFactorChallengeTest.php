<?php

use App\Enums\Bidang;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;

/*
| Fase D-2 — 2FA challenge E2E (ADR-0009). The two-factor-challenge page is a
| CUSTOM Blade view (resources/views/filament/pages/auth/two-factor-challenge)
| driven by a Livewire form. Feature tests exercise it via Livewire::test; this
| drives the REAL page in headless Chrome, the one thing those tests cannot see:
| the served form actually submitting and the 2FA gate holding.
|
| The TOTP code is generated the SAME way the app verifies it (pragmarx/google2fa,
| the lib bundled with Fortify). We never disable 2FA — that would test a system
| different from production; we compute a valid code and type it, exactly as a
| user copies it from their authenticator app.
*/

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Create an L3 Manager (2FA is mandatory for levels 1–3) with 2FA already
 * enrolled and confirmed. Returns the user and the plaintext TOTP secret.
 *
 * @return array{0: User, 1: string}
 */
function enrolledL3(): array
{
    $user = User::factory()->create([
        'name' => 'Manajer Uji 2FA',
        'email' => 'manajer.dusk@contoh.test',
        'password' => Hash::make('password'),
        'role_id' => Role::where('name', Role::NAME_MANAGER)->value('id'),
        'bidang' => Bidang::Cufid,
    ]);

    // Enable + confirm 2FA the way the enrollment action does — never disabled.
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $user->refresh();

    return [$user, decrypt($user->two_factor_secret)];
}

/** A valid 6-digit TOTP for the given secret, computed like the app does. */
function totpFor(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** Log the user in through the real Filament login form. */
function submitLogin(Browser $browser, User $user): Browser
{
    return $browser->visit('/sistem/login')
        ->waitFor('input[type=email]')
        ->type('input[type=email]', $user->email)
        ->type('input[type=password]', 'password')
        ->click('button[type=submit]');
}

it('gates an L3 account behind the 2FA challenge and admits it with a valid TOTP', function () {
    [$user, $secret] = enrolledL3();

    $this->browse(function (Browser $browser) use ($user, $secret) {
        // Login lands on the challenge, NOT the panel — the 2FA gate is up.
        submitLogin($browser, $user)
            ->waitForLocation('/sistem/two-factor-challenge')
            ->assertSee('Verifikasi Dua Langkah');

        // A valid TOTP (generated fresh, right before typing) clears the gate.
        $browser->type('input[type=text]', totpFor($secret))
            ->press('Verifikasi')
            ->waitForLocation('/sistem')     // redirected into the panel dashboard
            ->assertSee($user->name);        // the topbar user menu renders
    });
});

it('rejects a wrong TOTP and keeps the account out of the panel', function () {
    [$user] = enrolledL3();

    $this->browse(function (Browser $browser) use ($user) {
        submitLogin($browser, $user)
            ->waitForLocation('/sistem/two-factor-challenge')
            ->assertSee('Verifikasi Dua Langkah');

        // A wrong code is refused: the page shows the validation error and the
        // browser stays on the challenge — it never reaches the panel.
        $browser->type('input[type=text]', '000000')
            ->press('Verifikasi')
            ->waitForText('Kode autentikasi tidak valid.')
            ->assertPathIs('/sistem/two-factor-challenge');
    });
});
