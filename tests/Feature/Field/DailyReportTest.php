<?php

use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Enums\ReportMediaType;
use App\Exceptions\DailyReportException;
use App\Models\DailyReport;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\DailyReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->reports = app(DailyReportService::class);
});

function drRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// Write — Mandor in the project's bidang only, one per project per day
// ---------------------------------------------------------------------------

it('authorizes filing to a Mandor in the project bidang only', function () {
    $cufid = Project::factory()->inBidang(Bidang::Cufid)->create();

    expect(drRoled('mandor', Bidang::Cufid)->can('createDailyReport', $cufid))->toBeTrue()
        ->and(drRoled('mandor', Bidang::Cc)->can('createDailyReport', $cufid))->toBeFalse()
        ->and(drRoled('manager', Bidang::Cufid)->can('createDailyReport', $cufid))->toBeFalse() // Manager view-only
        ->and(drRoled('direktur')->can('createDailyReport', $cufid))->toBeTrue();
});

it('allows only one report per project per day', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $mandor = drRoled('mandor', Bidang::Cufid);

    $report = $this->reports->create($project, $mandor, '2026-07-06', 'Cor lantai 1');
    $this->reports->addMedia($report, ReportMediaType::Photo, 'reports/cor.jpg', 'progres cor');

    expect($report->media()->count())->toBe(1)
        ->and((int) $report->mandor_id)->toBe($mandor->id);

    expect(fn () => $this->reports->create($project, $mandor, '2026-07-06', 'Duplikat'))
        ->toThrow(DailyReportException::class);
});

// ---------------------------------------------------------------------------
// View — owning consumer + financing bank; others scoped/denied
// ---------------------------------------------------------------------------

it('lets the owning consumer and the financing bank view, others denied', function () {
    $konsumen = drRoled('konsumen');
    $bank = drRoled('mitra_pembiayaan');
    $project = Project::factory()->inBidang(Bidang::Cufid)->ownedBy($konsumen)->create([
        'is_financed' => true, 'bank_mitra_id' => $bank->id,
    ]);
    $report = DailyReport::factory()->forProject($project)->create();

    expect($konsumen->can('view', $report))->toBeTrue()          // auto-linked to its project
        ->and($bank->can('view', $report))->toBeTrue()           // read-only financing monitoring
        ->and(drRoled('konsumen')->can('view', $report))->toBeFalse()          // another consumer
        ->and(drRoled('mitra_pembiayaan')->can('view', $report))->toBeFalse()  // another bank
        ->and(drRoled('mandor', Bidang::Cufid)->can('view', $report))->toBeTrue()
        ->and(drRoled('mandor', Bidang::Cc)->can('view', $report))->toBeFalse()
        ->and(drRoled('manager', Bidang::Cufid)->can('view', $report))->toBeTrue()
        ->and(drRoled('hr')->can('view', $report))->toBeFalse(); // HR has no field access
});

// ---------------------------------------------------------------------------
// CRITICAL: a daily report never advances progress / unlocks a term
// ---------------------------------------------------------------------------

it('never advances project progress or unlocks a payment term', function () {
    $konsumen = drRoled('konsumen');
    $project = Project::factory()->inBidang(Bidang::Cufid)->ownedBy($konsumen)
        ->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00', 'progress_percent' => 0]);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $project->refresh();

    $mandor = drRoled('mandor', Bidang::Cufid);
    $this->reports->create($project, $mandor, '2026-07-06', 'Progres 60% versi lapangan', 'Sudah 60% menurut mandor');

    // progress_percent is untouched, and the progress50 term stays LOCKED —
    // a report is not a payment trigger (advancing progress stays with ProgressService).
    $project->refresh();
    $progressTerm = $project->installments()->where('due_condition', DueCondition::Progress50->value)->sole();

    expect((float) $project->progress_percent)->toBe(0.0)
        ->and($progressTerm->status)->toBe(InstallmentStatus::Locked);
});
