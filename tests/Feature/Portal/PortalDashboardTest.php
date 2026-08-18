<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// Shared helpers (guarded so the file also runs in isolation, independent of
// PortalAuthTest which declares the same names).
if (! function_exists('portalConsumer')) {
    function portalConsumer(bool $verified = true): User
    {
        $factory = User::factory();

        if (! $verified) {
            $factory = $factory->unverified();
        }

        return $factory->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
    }
}

if (! function_exists('portalStaff')) {
    function portalStaff(): User
    {
        return User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    }
}

function portalOwnedProject(User $consumer, array $attributes = []): Project
{
    return Project::factory()->create(array_merge([
        'konsumen_id' => $consumer->id,
    ], $attributes));
}

// ---------------------------------------------------------------------------
// The hard line: a consumer sees ONLY their own projects
// ---------------------------------------------------------------------------

it('lists only the consumer’s own projects', function () {
    $me = portalConsumer();
    $other = portalConsumer();

    portalOwnedProject($me, ['title' => 'Rumah Saya']);
    portalOwnedProject($other, ['title' => 'Rumah Orang Lain']);

    $this->actingAs($me)
        ->get('/portal')
        ->assertOk()
        ->assertSee('Rumah Saya')
        ->assertDontSee('Rumah Orang Lain');
});

it('shows a project’s detail to its owner with correct data', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me, [
        'title' => 'Renovasi Dapur',
        'status' => ProjectStatus::Active,
        'progress_percent' => 40,
    ]);

    $this->actingAs($me)
        ->get(route('portal.projects.show', $project))
        ->assertOk()
        ->assertSee('Renovasi Dapur')
        ->assertSee(ProjectStatus::Active->label())   // "Berjalan"
        ->assertSee('40%');
});

it('forbids viewing another consumer’s project via direct URL (no bypass)', function () {
    $me = portalConsumer();
    $other = portalConsumer();
    $theirs = portalOwnedProject($other);

    $this->actingAs($me)
        ->get(route('portal.projects.show', $theirs))
        ->assertForbidden();
});

it('404s an unknown project id', function () {
    $this->actingAs(portalConsumer())
        ->get('/portal/projects/999999')
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// A bare project (no submitted designs/RAB) shows no actions; payment/BAST
// actions are still absent (P-4/P-5).
// ---------------------------------------------------------------------------

it('shows no approve/pay/sign actions on a bare project', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);

    $this->actingAs($me)
        ->get(route('portal.projects.show', $project))
        ->assertOk()
        ->assertDontSee('Setujui')       // no submitted design/RAB to approve
        ->assertDontSee('Bayar')         // payment is P-4
        ->assertDontSee('Tanda tangan'); // BAST signing is P-5
});

// ---------------------------------------------------------------------------
// P-1 separation still holds with data present
// ---------------------------------------------------------------------------

it('keeps staff out of the portal dashboard and project detail', function () {
    $consumer = portalConsumer();
    $project = portalOwnedProject($consumer);

    $this->actingAs(portalStaff())->get('/portal')->assertForbidden();
    $this->actingAs(portalStaff())->get(route('portal.projects.show', $project))->assertForbidden();
});
