<?php

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Exceptions\AttendanceException;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RecipientResolver;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Payroll week Mon 2026-07-06 .. Sat 2026-07-11.
const PN_START = '2026-07-06';
const PN_END = '2026-07-11';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function pnUser(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

/** Generate a payroll for one worker present $present days at a distinctive wage. */
function pnGenerate(int $present = 3, string $wage = '333000.00'): Payroll
{
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => $wage]);

    $att = app(AttendanceService::class);
    foreach (array_slice(['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10', '2026-07-11'], 0, $present) as $d) {
        $att->record($employee, $project, $d, AttendanceStatus::Hadir);
    }

    return app(PayrollService::class)->generate(PN_START, PN_END);
}

function pnCount(User $user, string $event): int
{
    return $user->notifications()->where('data->event', $event)->count();
}

function pnBody(User $user, string $event): string
{
    return (string) $user->notifications()->where('data->event', $event)->latest()->first()?->data['body'];
}

/** Assert a body carries a period but NO money (no wage, no total, no Rp, no decimal amount). */
function pnAssertNoMoney(string $body): void
{
    expect($body)
        ->not->toContain('333000')                 // per-worker wage
        ->and($body)->not->toContain('999000')     // 3 × 333000 total
        ->and($body)->not->toContain('Rp')
        ->and($body)->not->toMatch('/\d[\d.,]*\.\d{2}\b/'); // any decimal money
}

// ---------------------------------------------------------------------------
// E9 — generated → HR + Finance + overseers ONLY; no money; no worker
// ---------------------------------------------------------------------------

it('notifies HR, Finance and overseers when payroll is generated, no one else', function () {
    $hr = pnUser('hr');
    $finance = pnUser('finance');
    $owner = pnUser('owner');
    $direktur = pnUser('direktur');
    $manager = pnUser('manager', Bidang::Cufid);
    $konsumen = pnUser('konsumen');

    pnGenerate();

    foreach ([$hr, $finance, $owner, $direktur] as $u) {
        expect(pnCount($u, 'payroll.generated'))->toBe(1, "{$u->role->name} should be notified");
    }
    // Payroll is not a Manager's or a consumer's concern.
    foreach ([$manager, $konsumen] as $u) {
        expect(pnCount($u, 'payroll.generated'))->toBe(0, "{$u->role->name} must not be notified");
    }

    // Exactly the four internal recipients — never a worker (Employee is not an
    // account, §7): every notification is addressed to a User.
    expect(DB::table('notifications')->where('data->event', 'payroll.generated')->count())->toBe(4)
        ->and(DB::table('notifications')->where('data->event', 'payroll.generated')
            ->where('notifiable_type', '!=', (new User)->getMorphClass())->count())->toBe(0);

    pnAssertNoMoney(pnBody($hr, 'payroll.generated'));
    expect(pnBody($hr, 'payroll.generated'))->toContain('2026-07-06'); // period is allowed
});

it('keeps one generated knock across a re-generated draft (idempotent)', function () {
    $hr = pnUser('hr');
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '333000.00']);
    $att = app(AttendanceService::class);
    $att->record($employee, $project, '2026-07-06', AttendanceStatus::Hadir);

    app(PayrollService::class)->generate(PN_START, PN_END);
    $att->record($employee, $project, '2026-07-07', AttendanceStatus::Hadir);
    app(PayrollService::class)->generate(PN_START, PN_END); // re-generate the draft

    expect(pnCount($hr, 'payroll.generated'))->toBe(1);
});

// ---------------------------------------------------------------------------
// E10 — paid → HR + Finance + overseers; no money; expense/lock once
// ---------------------------------------------------------------------------

it('notifies HR, Finance and overseers when payroll is paid, without any amount', function () {
    $hr = pnUser('hr');
    $finance = pnUser('finance');
    $owner = pnUser('owner');
    $direktur = pnUser('direktur');
    $manager = pnUser('manager', Bidang::Cufid);

    $payroll = pnGenerate();
    app(PayrollService::class)->pay($payroll, $finance);

    foreach ([$hr, $finance, $owner, $direktur] as $u) {
        expect(pnCount($u, 'payroll.paid'))->toBe(1, "{$u->role->name} should be notified");
    }
    expect(pnCount($manager, 'payroll.paid'))->toBe(0);

    pnAssertNoMoney(pnBody($hr, 'payroll.paid'));

    // Regression (6-2): the salary expense is posted exactly once.
    expect(Transaction::where('reference_type', Transaction::REF_PAYROLL)->where('reference_id', $payroll->id)->count())->toBe(1);
});

it('is idempotent on pay — a second call throws and never re-notifies', function () {
    $finance = pnUser('finance');
    $hr = pnUser('hr');
    $payroll = pnGenerate();

    app(PayrollService::class)->pay($payroll, $finance);
    expect(fn () => app(PayrollService::class)->pay($payroll->refresh(), $finance))->toThrow(Exception::class);

    expect(pnCount($hr, 'payroll.paid'))->toBe(1)
        ->and(Transaction::where('reference_type', Transaction::REF_PAYROLL)->where('reference_id', $payroll->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Failure isolation — a notification fault never fails the payout
// ---------------------------------------------------------------------------

it('never fails the payout when notifying throws', function () {
    $finance = pnUser('finance');
    $payroll = pnGenerate();

    $this->app->instance(RecipientResolver::class, new class extends RecipientResolver
    {
        public function recipientsFor(string $event, Model $entity): EloquentCollection
        {
            throw new RuntimeException('notification backend down');
        }
    });

    $txn = app(PayrollService::class)->pay($payroll, $finance); // must not throw

    // The expense posted exactly once...
    expect($txn)->toBeInstanceOf(Transaction::class)
        ->and(Transaction::where('reference_type', Transaction::REF_PAYROLL)->where('reference_id', $payroll->id)->count())->toBe(1);

    // ...and the ADR-0016 lock still took hold: recording new in-period
    // attendance is now refused (the lock is behavioural, not a column).
    $freshEmployee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    $freshProject = Project::factory()->inBidang(Bidang::Cufid)->create();
    expect(fn () => app(AttendanceService::class)->record($freshEmployee, $freshProject, '2026-07-08', AttendanceStatus::Hadir))
        ->toThrow(AttendanceException::class);
});
