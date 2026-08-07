<?php

use App\Enums\Bidang;
use App\Enums\FinancingDocumentStatus;
use App\Enums\FinancingStatus;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RecipientResolver;
use App\Services\FinancingDocumentService;
use App\Services\FinancingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function finUser(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $role)->value('id'),
        'bidang' => $bidang,
    ]);
}

/**
 * A financing on a Cufid project: owned by $owner, banked by $bank.
 */
function finFinancing(User $owner, User $bank, FinancingStatus $status, string $amount = '999000000.00'): Financing
{
    $project = Project::factory()->inBidang(Bidang::Cufid)->ownedBy($owner)->create();

    return Financing::factory()
        ->forProject($project)->ownedBy($owner)->forBank($bank)
        ->status($status)
        ->create(['amount' => $amount]);
}

function finCount(User $user, string $event): int
{
    return $user->notifications()->where('data->event', $event)->count();
}

function finBody(User $user, string $event): string
{
    return (string) $user->notifications()->where('data->event', $event)->latest()->first()?->data['body'];
}

// ---------------------------------------------------------------------------
// E6 — lifecycle transition → applicant + OWNING bank only; no amount
// ---------------------------------------------------------------------------

it('notifies the applicant and the owning bank on a lifecycle change, no one else', function () {
    $owner = finUser('konsumen');
    $bankOwn = finUser('mitra_pembiayaan');
    $bankOther = finUser('mitra_pembiayaan');
    $manager = finUser('manager', Bidang::Cufid);
    $finance = finUser('finance');

    $financing = finFinancing($owner, $bankOwn, FinancingStatus::Submitted);
    app(FinancingService::class)->transition($financing, FinancingStatus::Interview);

    expect(finCount($owner, 'financing.status_changed'))->toBe(1)
        ->and(finCount($bankOwn, 'financing.status_changed'))->toBe(1)
        // §6.5 — a bank that does not finance this project hears nothing.
        ->and(finCount($bankOther, 'financing.status_changed'))->toBe(0)
        ->and(finCount($manager, 'financing.status_changed'))->toBe(0)
        ->and(finCount($finance, 'financing.status_changed'))->toBe(0);

    // Neutral body: the status label, never the amount.
    expect(finBody($owner, 'financing.status_changed'))
        ->toContain('Wawancara')
        ->and(finBody($owner, 'financing.status_changed'))->not->toContain('999000000');
});

// ---------------------------------------------------------------------------
// E7 — disbursed → applicant + owning bank + cash overseers; income once
// ---------------------------------------------------------------------------

it('notifies the applicant, owning bank and cash overseers on disbursement', function () {
    $owner = finUser('konsumen');
    $bankOwn = finUser('mitra_pembiayaan');
    $bankOther = finUser('mitra_pembiayaan');
    $finance = finUser('finance');
    $ownerRole = finUser('owner');
    $direktur = finUser('direktur');
    $manager = finUser('manager', Bidang::Cufid);

    $financing = finFinancing($owner, $bankOwn, FinancingStatus::Approved, '888000000.00');
    $txn = app(FinancingService::class)->disburse($financing, $finance);

    foreach ([$owner, $bankOwn, $finance, $ownerRole, $direktur] as $u) {
        expect(finCount($u, 'financing.disbursed'))->toBe(1, "{$u->role->name} should be notified");
    }
    // Another bank and a Manager are never told.
    expect(finCount($bankOther, 'financing.disbursed'))->toBe(0)
        ->and(finCount($manager, 'financing.disbursed'))->toBe(0);

    // Body carries no amount.
    expect(finBody($owner, 'financing.disbursed'))->not->toContain('888000000');

    // Regression (4-2): the income row is posted exactly once.
    expect(Transaction::forFinancings()->where('reference_id', $financing->id)->count())->toBe(1)
        ->and($txn->reference_id)->toBe($financing->id);
});

it('is idempotent on disbursement — a second call throws and never re-notifies', function () {
    $owner = finUser('konsumen');
    $bankOwn = finUser('mitra_pembiayaan');
    $financing = finFinancing($owner, $bankOwn, FinancingStatus::Approved);

    app(FinancingService::class)->disburse($financing);
    expect(fn () => app(FinancingService::class)->disburse($financing->refresh()))->toThrow(Exception::class);

    expect(finCount($owner, 'financing.disbursed'))->toBe(1)
        ->and(Transaction::forFinancings()->where('reference_id', $financing->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// E8 — document review → ONLY the owning consumer; no note/content in body
// ---------------------------------------------------------------------------

it('notifies only the owning consumer on document review, hiding the reason', function () {
    $owner = finUser('konsumen');
    $bankOwn = finUser('mitra_pembiayaan');
    $bankOther = finUser('mitra_pembiayaan');
    $manager = finUser('manager', Bidang::Cufid);
    $finance = finUser('finance');

    $financing = finFinancing($owner, $bankOwn, FinancingStatus::DocsRequired);
    $document = FinancingDocument::factory()->forFinancing($financing)
        ->status(FinancingDocumentStatus::Pending)->create(['name' => 'KTP']);

    // The bank rejects with a sensitive reason.
    app(FinancingDocumentService::class)->reject($document, $bankOwn, 'SECRET_REASON_penghasilan_tidak_cukup');

    // Only the consumer is told.
    expect(finCount($owner, 'financing_document.reviewed'))->toBe(1);
    foreach ([$bankOwn, $bankOther, $manager, $finance] as $u) {
        expect(finCount($u, 'financing_document.reviewed'))->toBe(0, "{$u->role->name} must not be notified");
    }

    // The body names the document + status, never the reviewer's reason.
    $body = finBody($owner, 'financing_document.reviewed');
    expect($body)->toContain('KTP')
        ->and($body)->toContain('Ditolak')
        ->and($body)->not->toContain('SECRET_REASON');
});

// ---------------------------------------------------------------------------
// §6.5 boundary — a non-financing bank never accrues ANY notification
// ---------------------------------------------------------------------------

it('never leaks any financing notification to a bank outside the deal', function () {
    $owner = finUser('konsumen');
    $bankOwn = finUser('mitra_pembiayaan');
    $bankOther = finUser('mitra_pembiayaan');

    $financing = finFinancing($owner, $bankOwn, FinancingStatus::Approved);
    app(FinancingService::class)->transition(
        finFinancing($owner, $bankOwn, FinancingStatus::Submitted),
        FinancingStatus::Interview,
    );
    app(FinancingService::class)->disburse($financing);
    // A separate docs_required financing to also exercise E8 (document review).
    $f2 = finFinancing($owner, $bankOwn, FinancingStatus::DocsRequired);
    $doc2 = FinancingDocument::factory()->forFinancing($f2)->status(FinancingDocumentStatus::Pending)->create(['name' => 'KK']);
    app(FinancingDocumentService::class)->accept($doc2, $bankOwn);

    // The outsider bank has accrued nothing at all.
    expect($bankOther->notifications()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Failure isolation — a notification fault never fails the disbursement
// ---------------------------------------------------------------------------

it('never fails the disbursement when notifying throws', function () {
    $owner = finUser('konsumen');
    $bankOwn = finUser('mitra_pembiayaan');
    $financing = finFinancing($owner, $bankOwn, FinancingStatus::Approved);

    $this->app->instance(RecipientResolver::class, new class extends RecipientResolver
    {
        public function recipientsFor(string $event, Model $entity): Collection
        {
            throw new RuntimeException('notification backend down');
        }
    });

    $txn = app(FinancingService::class)->disburse($financing); // must not throw

    expect($txn)->toBeInstanceOf(Transaction::class)
        ->and($financing->refresh()->status)->toBe(FinancingStatus::Disbursed)
        ->and(Transaction::forFinancings()->where('reference_id', $financing->id)->count())->toBe(1);
});
