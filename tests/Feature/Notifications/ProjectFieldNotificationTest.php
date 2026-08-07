<?php

use App\Enums\Bidang;
use App\Enums\PurchaseOrderStatus;
use App\Enums\RabStatus;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Rab;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RecipientResolver;
use App\Services\DailyReportService;
use App\Services\ProgressService;
use App\Services\PurchaseOrderService;
use App\Services\RabService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function n4User(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $role)->value('id'),
        'bidang' => $bidang,
    ]);
}

function n4Project(User $owner): Project
{
    return Project::factory()->inBidang(Bidang::Cufid)->ownedBy($owner)->create();
}

/** A draft PO on $project (10×50000 + 4×125000 = 1,000,000). */
function n4DraftPo(Project $project): PurchaseOrder
{
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create(['total' => '0.00']);
    $po->items()->create(['description' => 'Semen', 'unit' => 'sak', 'quantity' => '10.00', 'unit_price' => '50000.00', 'subtotal' => '0.00']);
    $po->items()->create(['description' => 'Besi', 'unit' => 'batang', 'quantity' => '4.00', 'unit_price' => '125000.00', 'subtotal' => '0.00']);

    return $po;
}

function n4Count(User $user, string $event): int
{
    return $user->notifications()->where('data->event', $event)->count();
}

function n4Body(User $user, string $event): string
{
    return (string) $user->notifications()->where('data->event', $event)->latest()->first()?->data['body'];
}

// ---------------------------------------------------------------------------
// E4 — progress.updated → owning consumer + bidang Manager ONLY
// ---------------------------------------------------------------------------

it('notifies the consumer and bidang Manager on a progress change, with the percent but no money', function () {
    $owner = n4User('konsumen');
    $managerCufid = n4User('manager', Bidang::Cufid);
    $managerCc = n4User('manager', Bidang::Cc);
    $finance = n4User('finance');
    $other = n4User('konsumen');

    $project = n4Project($owner);
    app(ProgressService::class)->setProgress($project, 60);

    expect(n4Count($owner, 'progress.updated'))->toBe(1)
        ->and(n4Count($managerCufid, 'progress.updated'))->toBe(1);
    foreach ([$managerCc, $finance, $other] as $u) {
        expect(n4Count($u, 'progress.updated'))->toBe(0, "{$u->role->name} must not be notified");
    }

    // Percent is allowed; a money-shaped decimal is not.
    expect(n4Body($owner, 'progress.updated'))->toContain('60%')
        ->and(n4Body($owner, 'progress.updated'))->not->toMatch('/\d[\d.,]*\.\d{2}\b/');
});

it('dispatches distinct progress steps but dedupes an identical repeat', function () {
    $owner = n4User('konsumen');
    n4User('manager', Bidang::Cufid);
    $project = n4Project($owner);

    $svc = app(ProgressService::class);
    $svc->setProgress($project, 30);
    $svc->setProgress($project->refresh(), 60); // a real step → a second notification
    $svc->setProgress($project->refresh(), 60); // same value → no-op, no new notification

    expect(n4Count($owner, 'progress.updated'))->toBe(2);
});

// ---------------------------------------------------------------------------
// E5 — daily_report.created → consumer + Manager; a KNOCK with no content
// ---------------------------------------------------------------------------

it('notifies the consumer and Manager on a daily report but leaks no report content', function () {
    $owner = n4User('konsumen');
    $managerCufid = n4User('manager', Bidang::Cufid);
    $managerCc = n4User('manager', Bidang::Cc);
    $mandor = n4User('mandor', Bidang::Cufid);

    $project = n4Project($owner);

    app(DailyReportService::class)->create(
        $project, $mandor, '2026-08-07',
        'SECRET_DESCRIPTION_material_terpasang',
        'SECRET_PROGRESS_NOTE_70_persen',
    );

    expect(n4Count($owner, 'daily_report.created'))->toBe(1)
        ->and(n4Count($managerCufid, 'daily_report.created'))->toBe(1)
        // The Mandor wrote it — not a recipient. Other bidang Manager — no.
        ->and(n4Count($mandor, 'daily_report.created'))->toBe(0)
        ->and(n4Count($managerCc, 'daily_report.created'))->toBe(0);

    // The body is a knock only — no description, no progress note.
    $body = n4Body($owner, 'daily_report.created');
    expect($body)->not->toContain('SECRET_DESCRIPTION')
        ->and($body)->not->toContain('SECRET_PROGRESS_NOTE');
});

it('does not re-notify on a re-synced (idempotent) daily report', function () {
    $owner = n4User('konsumen');
    n4User('manager', Bidang::Cufid);
    $project = n4Project($owner);
    $mandor = n4User('mandor', Bidang::Cufid);

    $svc = app(DailyReportService::class);
    $svc->create($project, $mandor, '2026-08-07', 'laporan', null, 'client-abc');
    $svc->create($project, $mandor, '2026-08-07', 'laporan', null, 'client-abc'); // same client_id → returns existing

    expect(n4Count($owner, 'daily_report.created'))->toBe(1);
});

// ---------------------------------------------------------------------------
// E11 — po.received → bidang Manager + Finance; NOT the consumer; no price
// ---------------------------------------------------------------------------

it('notifies the Manager and Finance on PO receipt, never the consumer and never a price', function () {
    $owner = n4User('konsumen');
    $managerCufid = n4User('manager', Bidang::Cufid);
    $managerCc = n4User('manager', Bidang::Cc);
    $finance = n4User('finance');

    $project = n4Project($owner);
    $po = n4DraftPo($project);
    app(PurchaseOrderService::class)->order($po, $finance);
    app(PurchaseOrderService::class)->receive($po, $finance);

    expect(n4Count($managerCufid, 'po.received'))->toBe(1)
        ->and(n4Count($finance, 'po.received'))->toBe(1)
        // Internal only — the consumer never sees material spend.
        ->and(n4Count($owner, 'po.received'))->toBe(0)
        ->and(n4Count($managerCc, 'po.received'))->toBe(0);

    // The 1,000,000 total must not appear in the body.
    expect(n4Body($finance, 'po.received'))
        ->not->toContain('1000000')
        ->and(n4Body($finance, 'po.received'))->not->toContain('1.000.000');
});

// ---------------------------------------------------------------------------
// E12 — rab.finalized → consumer + Manager; Finance OFF-map; no RAB total
// ---------------------------------------------------------------------------

it('notifies the consumer and Manager on RAB finalisation, not Finance, and hides the total', function () {
    $owner = n4User('konsumen');
    $managerCufid = n4User('manager', Bidang::Cufid);
    $managerCc = n4User('manager', Bidang::Cc);
    $finance = n4User('finance');

    $project = n4Project($owner);
    $rab = Rab::factory()->status(RabStatus::Submitted)->create([
        'project_id' => $project->id,
        'grand_total' => '777000000.00',
    ]);

    app(RabService::class)->approve($rab, $owner);

    expect(n4Count($owner, 'rab.finalized'))->toBe(1)
        ->and(n4Count($managerCufid, 'rab.finalized'))->toBe(1)
        // Finance learns cash at payment, not at contract (default, RabPolicy has
        // no Finance role); other-bidang Manager is out of scope.
        ->and(n4Count($finance, 'rab.finalized'))->toBe(0)
        ->and(n4Count($managerCc, 'rab.finalized'))->toBe(0);

    expect(n4Body($owner, 'rab.finalized'))->not->toContain('777000000');
});

// ---------------------------------------------------------------------------
// Failure isolation — a notification fault never fails the business action
// ---------------------------------------------------------------------------

it('never fails the progress update when notifying throws', function () {
    $owner = n4User('konsumen');
    n4User('manager', Bidang::Cufid);
    $project = n4Project($owner);

    $this->app->instance(RecipientResolver::class, new class extends RecipientResolver
    {
        public function recipientsFor(string $event, Model $entity): Collection
        {
            throw new RuntimeException('notification backend down');
        }
    });

    $result = app(ProgressService::class)->setProgress($project, 50); // must not throw

    // The progress change (and its installment unlock) stands regardless.
    expect((string) $result->refresh()->progress_percent)->toBe('50.00')
        ->and(Transaction::count())->toBe(0); // (no payment here — just proving no crash)
});
