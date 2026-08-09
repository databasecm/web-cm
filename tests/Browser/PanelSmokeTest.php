<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

/*
| Fase D-1 — Dusk foundation smoke (ADR-0009). These run in a REAL headless
| Chrome against a served app, in a separate (non-blocking) CI job. Their only
| job here is to PROVE the browser stack works end to end — Chrome + driver +
| `php artisan serve` + a fresh MySQL migration — before the higher-value flows
| (D-2 2FA, D-3 the RAB builder repeater) are added.
|
| Login uses an L4 (mitra_pembiayaan) account: 2FA is optional at L4, so this
| smoke never touches the TOTP challenge (that is D-2's target).
*/

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('serves the public landing page in a real browser', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSee('Laravel'); // welcome view — proves serve + Chrome are alive
    });
});

it('logs an L4 account into the Filament panel and renders the dashboard', function () {
    $user = User::factory()->create([
        'name' => 'Mitra Uji Dusk',
        'email' => 'mitra.dusk@contoh.test',
        'password' => Hash::make('password'),
        'role_id' => Role::where('name', Role::NAME_MITRA_PEMBIAYAAN)->value('id'),
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit('/sistem/login')
            ->waitFor('input[type=email]')
            ->type('input[type=email]', $user->email)
            ->type('input[type=password]', 'password')
            ->click('button[type=submit]')
            ->waitForLocation('/sistem')      // redirected to the panel dashboard
            ->assertSee($user->name);         // the topbar user menu renders
    });
});
