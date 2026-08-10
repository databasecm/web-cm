<?php

use App\Enums\Bidang;
use App\Models\Ahsap;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;

/*
| Fase D-3 — RAB builder E2E (ADR-0009). THE reason Dusk exists here.
|
| The builder puts a Select (AHSAP) inside a Repeater. The Filament/Livewire test
| harness strips that nested Select value on submit, so RabsRelationManagerTest
| can only assert the action is offered and the picker is scoped — it CANNOT prove
| a picked AHSAP actually survives into the saved RAB. Only a real browser can.
|
| Here a Manager builds a real RAB through the actual UI — add a Repeater row,
| pick an AHSAP in the (Choices.js) Select, type a volume, save — and we assert
| the persisted RabItem carries the chosen ahsap_id + volume and the AHSAP price
| snapshot. That closes the gap the service-level guarantee (2B-4) deliberately
| left open: the builder Managers actually use produces a correct RAB.
|
| The AHSAP account is a Manager (L3), so 2FA is mandatory — we clear the real
| challenge first (the D-2 flow), never disabling 2FA.
*/

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingSeeder::class); // overhead 5 / margin 10 / ppn 11 defaults
});

/**
 * A Manager (bidang Cufid) with 2FA enrolled + confirmed.
 *
 * @return array{0: User, 1: string} the user and its plaintext TOTP secret
 */
function rabManagerWith2fa(): array
{
    $user = User::factory()->create([
        'name' => 'Manajer RAB E2E',
        'email' => 'manajer.rab@contoh.test',
        'password' => Hash::make('password'),
        'role_id' => Role::where('name', Role::NAME_MANAGER)->value('id'),
        'bidang' => Bidang::Cufid,
    ]);

    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $user->refresh();

    return [$user, decrypt($user->two_factor_secret)];
}

/** Log in through the real form and clear the real 2FA challenge (D-2 flow). */
function loginPast2fa(Browser $browser, User $user, string $secret): Browser
{
    return $browser->visit('/sistem/login')
        ->waitFor('input[type=email]')
        ->type('input[type=email]', $user->email)
        ->type('input[type=password]', 'password')
        ->click('button[type=submit]')
        ->waitForLocation('/sistem/two-factor-challenge')
        ->type('input[type=text]', app(Google2FA::class)->getCurrentOtp($secret))
        ->press('Verifikasi')
        ->waitForLocation('/sistem');
}

it('builds a RAB through the real UI, persisting the AHSAP picked inside the Repeater', function () {
    [$manager, $secret] = rabManagerWith2fa();

    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)->create();

    // A single AHSAP in the project's bidang with a clean price, so the preview
    // maths land on whole cents: 3 × 200000 = 600000 base, +5% +10% +11% stacked
    // → grand 769.230,00. (The saved RAB's own totals come from the component
    // breakdown and are covered by RabBuilderTest; here we assert the ITEM.)
    $ahsap = Ahsap::factory()->inBidang(Bidang::Cufid)->create([
        'name' => 'Pasang Bata Uji E2E',
        'unit' => 'm²',
        'base_price' => '200000',
    ]);

    $this->browse(function (Browser $browser) use ($manager, $secret, $project, $ahsap) {
        loginPast2fa($browser, $manager, $secret)
            ->visit("/sistem/projects/{$project->id}")
            ->waitFor('.fi-main', 20)                          // page shell mounted
            // The project view stacks several relation managers as TABS (Desain,
            // RAB, Termin, BAST); Filament renders only the ACTIVE tab, and Desain
            // is active by default. Bring the tabs into view and activate the "RAB"
            // tab so its (lazy) table and the "Buat RAB" header action render.
            ->waitFor('.fi-resource-relation-managers', 20)
            ->scrollIntoView('.fi-resource-relation-managers')
            ->waitForText('RAB', 20)
            ->press('RAB')
            ->waitForText('Buat RAB', 30)
            ->press('Buat RAB')
            // Modal open: the Repeater starts empty — add one row.
            ->waitFor('@rab-add-item', 15)
            ->click('@rab-add-item')
            ->waitFor('@rab-ahsap', 15)

            // Pick the AHSAP in the Choices.js Select nested in the Repeater
            // (the JS widget loads lazily, so wait for its inner control first).
            ->waitFor('@rab-ahsap .choices__inner', 15)
            ->click('@rab-ahsap .choices__inner')
            ->waitFor('@rab-ahsap .choices__list--dropdown [data-value="'.$ahsap->id.'"]', 15)
            ->click('@rab-ahsap .choices__list--dropdown [data-value="'.$ahsap->id.'"]')

            // Volume 3 (replace the default 1), then blur to commit the live field.
            ->clear('@rab-volume input')
            ->type('@rab-volume input', '3')
            ->keys('@rab-volume input', ['{tab}'])

            // The live preview reflecting BOTH nested values proves the Select did
            // not get stripped in the browser, and doubles as a commit barrier.
            ->waitForTextIn('@rab-preview', '769.230,00', 15)

            ->press('Simpan RAB');

        // Poll the DB — the ADR-0009 truth — until the built RAB carries a RabItem
        // for the AHSAP picked inside the Repeater. On timeout, dump why the save
        // didn't land (modal state / validation / submit button / server error).
        try {
            $browser->waitUsing(20, 0.5, fn (): bool => Rab::where('project_id', $project->id)
                ->whereHas('items', fn ($q) => $q->where('ahsap_id', $ahsap->id))
                ->exists());
        } catch (Throwable $e) {
            $html = $browser->driver->getPageSource();
            $fpos = strpos($html, 'fi-modal-footer');
            $footer = $fpos !== false ? substr($html, $fpos, 2500) : '(no fi-modal-footer in DOM)';
            $footer = preg_replace('/\s(wire:snapshot|x-data|wire:key|wire:model[.\w]*)="[^"]*"/', '', $footer);
            $serve = @file_get_contents('/tmp/serve.log');
            fwrite(STDERR, '[DIAG] '.json_encode([
                'modalOpen' => str_contains($html, 'rab-ahsap'),
                'validationError' => str_contains($html, 'fi-fo-field-wrp-error-message'),
                'simpanBtnPresent' => str_contains($html, 'Simpan RAB'),
                'rabInDb' => Rab::where('project_id', $project->id)->count(),
            ]).PHP_EOL
                ."[MODAL-FOOTER]\n".$footer.PHP_EOL
                ."[SERVE-TAIL]\n".($serve ? substr($serve, -2500) : '(none)').PHP_EOL);

            throw $e;
        }
    });

    // The real proof lives in the DB: the nested AHSAP survived the save.
    $rab = Rab::where('project_id', $project->id)->firstOrFail();
    expect($rab->version)->toBe(1)
        ->and($rab->items)->toHaveCount(1);

    $item = $rab->items->first();
    expect($item->ahsap_id)->toBe($ahsap->id)                 // Select-in-Repeater NOT stripped
        ->and($item->description)->toBe('Pasang Bata Uji E2E') // AHSAP name snapshot
        ->and($item->unit)->toBe('m²')
        ->and((float) $item->volume)->toBe(3.0)                // nested TextInput survived
        ->and($item->unit_price)->toBe('200000.00')           // price snapshot (ADR-0007)
        ->and($item->subtotal)->toBe('600000.00');            // 3 × 200000
});
