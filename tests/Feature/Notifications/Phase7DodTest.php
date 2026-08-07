<?php

use App\Enums\AttendanceStatus;
use App\Enums\BastParty;
use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\FinancingDocumentStatus;
use App\Enums\FinancingStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\RabStatus;
use App\Models\Employee;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\NotificationDispatcher;
use App\Notifications\PaymentPaidNotification;
use App\Notifications\PayrollPaidNotification;
use App\Services\AttendanceService;
use App\Services\BastService;
use App\Services\CheckoutService;
use App\Services\DailyReportService;
use App\Services\FinancingDocumentService;
use App\Services\FinancingService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\ProgressService;
use App\Services\PurchaseOrderService;
use App\Services\RabService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
|------------------------------------------------------------------------------
| Fase 7 — Notifications: Definition of Done (living specification)
|------------------------------------------------------------------------------
| One cross-event gate that locks the six invariants of the whole notification
| subsystem. Per-event nuance is proven in the sibling test files; this file is
| the contract they must keep collectively:
|   (a) idempotent          — one business event = one notification per recipient
|   (b) recipients (§6/§6.5) — the right accounts, and NO one else
|   (c) clean body           — never a money amount, never sensitive content
|   (d) channels from config — add mail/WA without touching a trigger (A3)
|   (e) read surfaces        — owner-only read/mark (Filament + API)
|   (f) accounts only        — workers (Employee) are never notifiable (§7)
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function dod7User(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

/** A Cufid project owned by $owner, RAB-frozen + checked out (Termin3) → Active. */
function dod7ActiveProject(User $owner, string $contract = '777000000.00'): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->inBidang(Bidang::Cufid)
        ->ownedBy($owner)->create(['contract_value' => $contract]);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    return $project->refresh();
}

function dod7Count(User $user, string $event): int
{
    return $user->notifications()->where('data->event', $event)->count();
}

/** Every stored notification body, across all recipients. */
function dod7AllBodies(): array
{
    return DB::table('notifications')->pluck('data')
        ->map(fn ($d) => (string) (json_decode($d, true)['body'] ?? ''))->all();
}

/**
 * Fire every Fase 7 business event once into one coherent world, and hand back
 * the cast + the distinctive money strings that must never surface in a body.
 */
function dod7World(): array
{
    $owner = dod7User('konsumen');
    $otherKonsumen = dod7User('konsumen');
    $bankOwn = dod7User('mitra_pembiayaan');
    $bankOther = dod7User('mitra_pembiayaan');
    $managerCufid = dod7User('manager', Bidang::Cufid);
    $managerCc = dod7User('manager', Bidang::Cc);
    $finance = dod7User('finance');
    $hr = dod7User('hr');
    $ownerRole = dod7User('owner');
    $direktur = dod7User('direktur');
    $mandor = dod7User('mandor', Bidang::Cufid);

    // Project lifecycle on one Cufid project owned by $owner.
    $project = Project::factory()->status(ProjectStatus::Draft)->inBidang(Bidang::Cufid)->ownedBy($owner)->create();
    $rab = Rab::factory()->status(RabStatus::Submitted)->create(['project_id' => $project->id, 'grand_total' => '777000000.00']);
    app(RabService::class)->approve($rab, $owner);                               // E12 rab.finalized
    (new CheckoutService)->checkout($project->refresh(), PaymentScheme::Termin3); // → Active
    $project->refresh();

    $checkout = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->pay($checkout, $finance);                        // E1 payment.paid
    app(ProgressService::class)->setProgress($project, 60);                      // E4 progress.updated
    $bast = app(BastService::class)->issue($project);                           // E2 bast.issued
    app(BastService::class)->recordSignature($bast, BastParty::Company);
    app(BastService::class)->recordSignature($bast->refresh(), BastParty::Customer); // E3 bast.signed
    app(DailyReportService::class)->create($project, $mandor, '2026-08-07', 'SECRET_REPORT_DESC', 'SECRET_REPORT_NOTE'); // E5

    // PO on the project (total 2 × 617000 = 1_234_000).
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create(['total' => '0.00']);
    $po->items()->create(['description' => 'Bahan', 'unit' => 'u', 'quantity' => '2.00', 'unit_price' => '617000.00', 'subtotal' => '0.00']);
    app(PurchaseOrderService::class)->order($po, $finance);
    app(PurchaseOrderService::class)->receive($po, $finance);                    // E11 po.received

    // Financing — a separate project each (one active financing per project).
    $f6 = Financing::factory()->forProject(Project::factory()->inBidang(Bidang::Cufid)->ownedBy($owner)->create())
        ->ownedBy($owner)->forBank($bankOwn)->status(FinancingStatus::Submitted)->create(['amount' => '999000000.00']);
    app(FinancingService::class)->transition($f6, FinancingStatus::Interview);   // E6 financing.status_changed

    $f7 = Financing::factory()->forProject(Project::factory()->inBidang(Bidang::Cufid)->ownedBy($owner)->create())
        ->ownedBy($owner)->forBank($bankOwn)->status(FinancingStatus::Approved)->create(['amount' => '888000000.00']);
    app(FinancingService::class)->disburse($f7, $finance);                       // E7 financing.disbursed

    $f8 = Financing::factory()->forProject(Project::factory()->inBidang(Bidang::Cufid)->ownedBy($owner)->create())
        ->ownedBy($owner)->forBank($bankOwn)->status(FinancingStatus::DocsRequired)->create(['amount' => '555000000.00']);
    $doc = FinancingDocument::factory()->forFinancing($f8)->status(FinancingDocumentStatus::Pending)->create(['name' => 'KTP']);
    app(FinancingDocumentService::class)->reject($doc, $bankOwn, 'SECRET_REJECT_REASON'); // E8

    // Payroll (worker wage 333000).
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '333000.00']);
    app(AttendanceService::class)->record($employee, $project, '2026-07-06', AttendanceStatus::Hadir);
    $payroll = app(PayrollService::class)->generate('2026-07-06', '2026-07-11'); // E9 payroll.generated
    app(PayrollService::class)->pay($payroll, $finance);                         // E10 payroll.paid

    return [
        'cast' => compact('owner', 'otherKonsumen', 'bankOwn', 'bankOther', 'managerCufid', 'managerCc', 'finance', 'hr', 'ownerRole', 'direktur', 'mandor', 'employee'),
        // Distinctive money strings that must appear in NO body.
        'amounts' => ['777000000', '999000000', '888000000', '555000000', '333000', '1234000', '1.234.000', '233100000'],
        'secrets' => ['SECRET_REPORT_DESC', 'SECRET_REPORT_NOTE', 'SECRET_REJECT_REASON'],
    ];
}

// ---------------------------------------------------------------------------
// (a) Idempotent — one business event = one notification per recipient
// ---------------------------------------------------------------------------

it('(a) delivers each event once per recipient, even on a repeat/retry', function () {
    $owner = dod7User('konsumen');
    $finance = dod7User('finance');
    $hr = dod7User('hr');

    // payment.paid — pay once, then re-dispatch the SAME event (simulated retry).
    $project = dod7ActiveProject($owner);
    $term = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->pay($term, $finance);
    app(NotificationDispatcher::class)->dispatch('payment.paid', $term, fn () => new PaymentPaidNotification($term));
    expect(dod7Count($owner, 'payment.paid'))->toBe(1)
        ->and(dod7Count($finance, 'payment.paid'))->toBe(1);

    // financing.disbursed — a second disburse throws; still one notification.
    $financing = Financing::factory()->forProject(Project::factory()->ownedBy($owner)->create())
        ->ownedBy($owner)->forBank(dod7User('mitra_pembiayaan'))->status(FinancingStatus::Approved)->create();
    app(FinancingService::class)->disburse($financing);
    expect(fn () => app(FinancingService::class)->disburse($financing->refresh()))->toThrow(Exception::class);
    expect(dod7Count($owner, 'financing.disbursed'))->toBe(1);

    // payroll.generated — re-generating the draft keeps a single knock.
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    app(AttendanceService::class)->record($employee, $project, '2026-07-06', AttendanceStatus::Hadir);
    app(PayrollService::class)->generate('2026-07-06', '2026-07-11');
    app(PayrollService::class)->generate('2026-07-06', '2026-07-11');
    expect(dod7Count($hr, 'payroll.generated'))->toBe(1);
});

// ---------------------------------------------------------------------------
// (b) Recipients — the right accounts per §6/§6.5, and NO one else
// ---------------------------------------------------------------------------

it('(b) addresses every event to exactly its entitled recipients', function () {
    ['cast' => $c] = dod7World();

    // E1 payment.paid — consumer + Finance + overseers; not Manager/other consumer.
    expect(dod7Count($c['owner'], 'payment.paid'))->toBe(1)
        ->and(dod7Count($c['finance'], 'payment.paid'))->toBe(1)
        ->and(dod7Count($c['ownerRole'], 'payment.paid'))->toBe(1)
        ->and(dod7Count($c['direktur'], 'payment.paid'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'payment.paid'))->toBe(0)
        ->and(dod7Count($c['otherKonsumen'], 'payment.paid'))->toBe(0);

    // E2/E3 BAST — consumer + bidang Manager (+ Finance on signed); never other bidang.
    expect(dod7Count($c['owner'], 'bast.issued'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'bast.issued'))->toBe(1)
        ->and(dod7Count($c['managerCc'], 'bast.issued'))->toBe(0)
        ->and(dod7Count($c['finance'], 'bast.issued'))->toBe(0)
        ->and(dod7Count($c['owner'], 'bast.signed'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'bast.signed'))->toBe(1)
        ->and(dod7Count($c['finance'], 'bast.signed'))->toBe(1)
        ->and(dod7Count($c['managerCc'], 'bast.signed'))->toBe(0);

    // E4 progress — consumer + bidang Manager; not Finance.
    expect(dod7Count($c['owner'], 'progress.updated'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'progress.updated'))->toBe(1)
        ->and(dod7Count($c['finance'], 'progress.updated'))->toBe(0)
        ->and(dod7Count($c['managerCc'], 'progress.updated'))->toBe(0);

    // E5 daily report — consumer + bidang Manager; NEVER the Mandor author.
    expect(dod7Count($c['owner'], 'daily_report.created'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'daily_report.created'))->toBe(1)
        ->and(dod7Count($c['mandor'], 'daily_report.created'))->toBe(0)
        ->and(dod7Count($c['managerCc'], 'daily_report.created'))->toBe(0);

    // E11 PO received — Manager + Finance; NOT the consumer (internal).
    expect(dod7Count($c['managerCufid'], 'po.received'))->toBe(1)
        ->and(dod7Count($c['finance'], 'po.received'))->toBe(1)
        ->and(dod7Count($c['owner'], 'po.received'))->toBe(0)
        ->and(dod7Count($c['managerCc'], 'po.received'))->toBe(0);

    // E12 RAB finalised — consumer + bidang Manager; Finance off-map.
    expect(dod7Count($c['owner'], 'rab.finalized'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'rab.finalized'))->toBe(1)
        ->and(dod7Count($c['finance'], 'rab.finalized'))->toBe(0);

    // E6/E7 financing (§6.5) — consumer + OWNING bank; the other bank hears nothing.
    expect(dod7Count($c['owner'], 'financing.status_changed'))->toBe(1)
        ->and(dod7Count($c['bankOwn'], 'financing.status_changed'))->toBe(1)
        ->and(dod7Count($c['bankOther'], 'financing.status_changed'))->toBe(0)
        ->and(dod7Count($c['managerCufid'], 'financing.status_changed'))->toBe(0)
        ->and(dod7Count($c['owner'], 'financing.disbursed'))->toBe(1)
        ->and(dod7Count($c['bankOwn'], 'financing.disbursed'))->toBe(1)
        ->and(dod7Count($c['finance'], 'financing.disbursed'))->toBe(1)
        ->and(dod7Count($c['bankOther'], 'financing.disbursed'))->toBe(0);

    // E8 document review — ONLY the owning consumer.
    expect(dod7Count($c['owner'], 'financing_document.reviewed'))->toBe(1)
        ->and(dod7Count($c['bankOwn'], 'financing_document.reviewed'))->toBe(0)
        ->and(dod7Count($c['managerCufid'], 'financing_document.reviewed'))->toBe(0)
        ->and(dod7Count($c['finance'], 'financing_document.reviewed'))->toBe(0);

    // E9/E10 payroll — HR + Finance + overseers only; not Manager, not consumer.
    expect(dod7Count($c['hr'], 'payroll.generated'))->toBe(1)
        ->and(dod7Count($c['finance'], 'payroll.generated'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'payroll.generated'))->toBe(0)
        ->and(dod7Count($c['owner'], 'payroll.generated'))->toBe(0)
        ->and(dod7Count($c['hr'], 'payroll.paid'))->toBe(1)
        ->and(dod7Count($c['finance'], 'payroll.paid'))->toBe(1)
        ->and(dod7Count($c['managerCufid'], 'payroll.paid'))->toBe(0);

    // §6.5 aggregate — a bank outside every deal accrues nothing at all.
    expect($c['bankOther']->notifications()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// (c) Clean body — no money amount, no sensitive content, anywhere
// ---------------------------------------------------------------------------

it('(c) never lets a money amount or sensitive content into any body', function () {
    ['amounts' => $amounts, 'secrets' => $secrets] = dod7World();

    $bodies = dod7AllBodies();
    expect($bodies)->not->toBeEmpty();

    foreach ($bodies as $body) {
        // No currency shape: no "Rp", no decimal amount like 1234.00.
        expect($body)->not->toContain('Rp')
            ->and($body)->not->toMatch('/\d[\d.,]*\.\d{2}\b/');

        // None of the distinctive figures used in the run.
        foreach ($amounts as $amount) {
            expect($body)->not->toContain($amount);
        }
        // No report text / reviewer reason.
        foreach ($secrets as $secret) {
            expect($body)->not->toContain($secret);
        }
    }
});

// ---------------------------------------------------------------------------
// (d) Channels from config — add mail/WA without touching a trigger (A3)
// ---------------------------------------------------------------------------

it('(d) reads delivery channels from config and is queued', function () {
    $notification = new PayrollPaidNotification(new Payroll);
    $user = dod7User('finance');

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification)->toBeInstanceOf(BaseNotification::class);

    config(['notifications.channels' => ['database']]);
    expect($notification->via($user))->toBe(['database']);

    // Adding mail + WhatsApp at go-live is a config change only — via() follows,
    // and no service trigger is touched.
    config(['notifications.channels' => ['database', 'mail', 'wa']]);
    expect($notification->via($user))->toBe(['database', 'mail', 'wa']);
});

// ---------------------------------------------------------------------------
// (e) Read surfaces — owner-only read/mark (Filament policy + Sanctum API)
// ---------------------------------------------------------------------------

it('(e) lets only the owner read/mark, via API and policy', function () {
    $owner = dod7User('konsumen');
    $stranger = dod7User('konsumen');

    // Produce a real notification for the owner.
    $project = dod7ActiveProject($owner);
    $term = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->pay($term, dod7User('finance'));
    $notification = $owner->notifications()->sole();

    // Filament + API share NotificationPolicy: only the owner may view/mark.
    expect($owner->can('view', $notification))->toBeTrue()
        ->and($stranger->can('view', $notification))->toBeFalse();

    // API: owner marks read; a stranger gets 404 (existence never confirmed).
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 1);
    $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
    expect($owner->unreadNotifications()->count())->toBe(0);

    Sanctum::actingAs($stranger);
    $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
});

// ---------------------------------------------------------------------------
// (f) Accounts only — workers (Employee) are never notifiable (§7)
// ---------------------------------------------------------------------------

it('(f) never addresses a notification to anything but a login account', function () {
    dod7World();

    // Every stored notification is addressed to a User (the only Notifiable).
    expect(DB::table('notifications')->count())->toBeGreaterThan(0)
        ->and(DB::table('notifications')->where('notifiable_type', '!=', (new User)->getMorphClass())->count())->toBe(0);

    // Structural guarantee: workers are data, not accounts.
    expect(in_array(Notifiable::class, class_uses_recursive(User::class), true))->toBeTrue()
        ->and(method_exists(Employee::class, 'notify'))->toBeFalse();
});
