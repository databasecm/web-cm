<?php

use App\Enums\BastParty;
use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Models\Bast;
use App\Models\Installment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BastPdf;
use App\Services\BastService;
use App\Services\CheckoutService;
use App\Services\PaymentReceiptPdf;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function docRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

/** Active project owned by $konsumen (Cufid), checkout term already PAID. */
function paidCheckout(User $konsumen): Installment
{
    $project = Project::factory()->ownedBy($konsumen)->inBidang(Bidang::Cufid)
        ->status(ProjectStatus::Rab)->create(['title' => 'Renovasi Dapur', 'contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    $checkout = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->pay($checkout);

    return $checkout->refresh();
}

/** A signed BAST on a fresh active project, signed by the given company+customer. */
function signedBast(User $konsumen, User $manager): Bast
{
    $project = Project::factory()->ownedBy($konsumen)->inBidang(Bidang::Cufid)
        ->status(ProjectStatus::Active)->create(['title' => 'Renovasi Dapur', 'contract_value' => '1000000.00']);

    $bast = app(BastService::class)->issue($project);
    app(BastService::class)->recordSignature($bast, BastParty::Company, $manager->id);
    app(BastService::class)->recordSignature($bast, BastParty::Customer, $konsumen->id);

    return $bast->refresh();
}

// ===========================================================================
// A) Payment receipt (kuitansi)
// ===========================================================================

it('renders the receipt with the stored ledger figures', function () {
    $konsumen = docRoled('konsumen');
    $konsumen->update(['name' => 'Budi Konsumen']);
    $checkout = paidCheckout($konsumen);

    $txn = Transaction::forInstallments()->where('reference_id', $checkout->id)->first();

    $html = view('pdf.payment-receipt', [
        'installment' => $checkout->load('project.konsumen'),
        'transaction' => $txn,
        'company' => config('company'),
    ])->render();

    expect($html)
        ->toContain('CV. Cimandiri')
        ->toContain('Kuitansi Pembayaran')
        ->toContain('Renovasi Dapur')
        ->toContain('Budi Konsumen')
        ->toContain('Rp 300.000,00')  // 30% of 1,000,000 (stored amount)
        ->toContain('LUNAS');
});

it('generates a real receipt PDF only reachable for a paid term', function () {
    $konsumen = docRoled('konsumen');
    $checkout = paidCheckout($konsumen);

    $bytes = app(PaymentReceiptPdf::class)->make($checkout)->output();
    expect(substr($bytes, 0, 4))->toBe('%PDF');

    // An unpaid term of the same project cannot be receipted.
    $unpaid = $checkout->project->installments()->where('due_condition', DueCondition::Progress50->value)->sole();
    expect(Gate::forUser($konsumen)->allows('downloadReceipt', $unpaid))->toBeFalse();
});

it('authorizes receipt download to the owner, Finance and overseers only', function () {
    $konsumen = docRoled('konsumen');
    $checkout = paidCheckout($konsumen);

    expect(Gate::forUser($konsumen)->allows('downloadReceipt', $checkout))->toBeTrue()
        ->and(Gate::forUser(docRoled('finance'))->allows('downloadReceipt', $checkout))->toBeTrue()
        ->and(Gate::forUser(docRoled('owner'))->allows('downloadReceipt', $checkout))->toBeTrue()
        ->and(Gate::forUser(docRoled('direktur'))->allows('downloadReceipt', $checkout))->toBeTrue()
        ->and(Gate::forUser(docRoled('manager', Bidang::Cufid))->allows('downloadReceipt', $checkout))->toBeFalse()
        ->and(Gate::forUser(docRoled('konsumen'))->allows('downloadReceipt', $checkout))->toBeFalse();
});

it('serves the receipt over the API for the owner and rejects others / unpaid', function () {
    $konsumen = docRoled('konsumen');
    $checkout = paidCheckout($konsumen);
    $unpaid = $checkout->project->installments()->where('due_condition', DueCondition::Progress50->value)->sole();

    Sanctum::actingAs($konsumen);
    $res = $this->get("/api/v1/installments/{$checkout->id}/receipt");
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');

    $this->get("/api/v1/installments/{$unpaid->id}/receipt")->assertForbidden();

    Sanctum::actingAs(docRoled('konsumen'));
    $this->get("/api/v1/installments/{$checkout->id}/receipt")->assertForbidden();
});

// ===========================================================================
// B) BAST document PDF
// ===========================================================================

it('renders the BAST with both signers and the handover statement', function () {
    $konsumen = docRoled('konsumen');
    $konsumen->update(['name' => 'Budi Konsumen']);
    $manager = docRoled('manager', Bidang::Cufid);
    $manager->update(['name' => 'Andi Manajer']);

    $bast = signedBast($konsumen, $manager);

    $html = view('pdf.bast', [
        'bast' => $bast->load(['project.konsumen', 'customerSigner', 'companySigner']),
        'company' => config('company'),
    ])->render();

    expect($html)
        ->toContain('Berita Acara Serah Terima')
        ->toContain('Renovasi Dapur')
        ->toContain('Budi Konsumen')   // consumer + customer signer
        ->toContain('Andi Manajer')    // company signer
        ->toContain('Ditandatangani'); // signed status
});

it('generates a real BAST PDF only for a signed BAST', function () {
    $konsumen = docRoled('konsumen');
    $manager = docRoled('manager', Bidang::Cufid);
    $bast = signedBast($konsumen, $manager);

    expect(substr(app(BastPdf::class)->make($bast)->output(), 0, 4))->toBe('%PDF');

    // A draft BAST is not a downloadable document.
    $draftProject = Project::factory()->status(ProjectStatus::Active)->create();
    $draft = app(BastService::class)->issue($draftProject);
    expect(Gate::forUser($konsumen)->allows('downloadPdf', $draft))->toBeFalse();
});

it('authorizes BAST PDF to the owner, the bidang Manager and the financing bank', function () {
    $konsumen = docRoled('konsumen');
    $manager = docRoled('manager', Bidang::Cufid);
    $bast = signedBast($konsumen, $manager);

    $bank = docRoled('mitra_pembiayaan');
    $bast->project->update(['is_financed' => true, 'bank_mitra_id' => $bank->id]);

    expect(Gate::forUser($konsumen)->allows('downloadPdf', $bast))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('downloadPdf', $bast))->toBeTrue()
        ->and(Gate::forUser($bank)->allows('downloadPdf', $bast))->toBeTrue()
        ->and(Gate::forUser(docRoled('manager', Bidang::Cc))->allows('downloadPdf', $bast))->toBeFalse()
        ->and(Gate::forUser(docRoled('konsumen'))->allows('downloadPdf', $bast))->toBeFalse();
});

it('serves the BAST PDF over the API for the owner and rejects a draft', function () {
    $konsumen = docRoled('konsumen');
    $manager = docRoled('manager', Bidang::Cufid);
    $bast = signedBast($konsumen, $manager);

    Sanctum::actingAs($konsumen);
    $this->get("/api/v1/bast/{$bast->id}/pdf")->assertOk();

    // A draft BAST on the consumer's own project cannot be downloaded.
    $ownDraftProject = Project::factory()->ownedBy($konsumen)->status(ProjectStatus::Active)->create();
    $draft = app(BastService::class)->issue($ownDraftProject);
    $this->get("/api/v1/bast/{$draft->id}/pdf")->assertForbidden();
});
