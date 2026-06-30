<?php

use App\Enums\Bidang;
use App\Enums\RabStatus;
use App\Models\Project;
use App\Models\Rab;
use App\Models\RabItem;
use App\Models\Role;
use App\Models\User;
use App\Services\RabPenawaranPdf;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function pdfKonsumen(): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
        'name' => 'Budi Konsumen',
        'phone' => '0811-2233',
    ]);
}

function pdfManager(Bidang $bidang): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

/**
 * A fully-snapshotted RAB whose stored totals are deliberately a round figure
 * that does NOT equal the sum of its items — proving the document mirrors the
 * frozen RAB columns rather than recomputing from the lines or live AHSAP.
 */
function snapshotRab(User $konsumen, Bidang $bidang = Bidang::Cufid, RabStatus $status = RabStatus::Approved): Rab
{
    $project = Project::factory()->ownedBy($konsumen)->inBidang($bidang)->create(['title' => 'Renovasi Dapur Budi']);

    $rab = Rab::factory()->for($project)->status($status)->create([
        'version' => 2,
        'total_material' => '700000.00',
        'total_upah' => '300000.00',
        'overhead_percent' => '5.0000',
        'overhead' => '50000.00',
        'margin_percent' => '10.0000',
        'margin' => '105000.00',
        'ppn_percent' => '11.0000',
        'ppn' => '126500.00',
        'grand_total' => '1281500.00',
    ]);

    RabItem::factory()->for($rab)->create([
        'description' => 'Pasang keramik lantai',
        'unit' => 'm²',
        'volume' => '12.5000',
        'unit_price' => '56000.00',
        'subtotal' => '700000.00',
    ]);

    return $rab;
}

// ---------------------------------------------------------------------------
// Content — the document mirrors the frozen RAB snapshot
// ---------------------------------------------------------------------------

it('renders the penawaran with the snapshot figures and branding', function () {
    $rab = snapshotRab(pdfKonsumen());

    $html = view('pdf.rab-penawaran', [
        'rab' => $rab->load(['project.konsumen', 'items']),
        'company' => config('company'),
    ])->render();

    expect($html)
        ->toContain('CV. Cimandiri')                 // kop
        ->toContain('Renovasi Dapur Budi')           // project title
        ->toContain('Budi Konsumen')                 // consumer
        ->toContain('Pasang keramik lantai')         // line item
        ->toContain('Rp 1.281.500,00')               // grand_total (snapshot)
        ->toContain('Rp 700.000,00')                 // total material / item subtotal
        ->toContain('11%');                          // snapshotted PPN rate
});

it('generates a real PDF document from the RAB', function () {
    $rab = snapshotRab(pdfKonsumen());

    $bytes = app(RabPenawaranPdf::class)->make($rab)->output();

    expect(substr($bytes, 0, 4))->toBe('%PDF')
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

// ---------------------------------------------------------------------------
// Authorization — policy gate (Manager bidangnya, status submitted/approved)
// ---------------------------------------------------------------------------

it('lets a Manager download in its bidang but not another bidang or a draft', function () {
    $rab = snapshotRab(pdfKonsumen(), Bidang::Cufid);
    $draft = snapshotRab(pdfKonsumen(), Bidang::Cufid, RabStatus::Draft);

    expect(Gate::forUser(pdfManager(Bidang::Cufid))->allows('downloadPdf', $rab))->toBeTrue()
        ->and(Gate::forUser(pdfManager(Bidang::Cc))->allows('downloadPdf', $rab))->toBeFalse()
        ->and(Gate::forUser(pdfManager(Bidang::Cufid))->allows('downloadPdf', $draft))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Authorization — consumer API (own RAB, submitted/approved only)
// ---------------------------------------------------------------------------

it('streams the PDF to the owning consumer over the API', function () {
    $me = pdfKonsumen();
    $rab = snapshotRab($me);

    Sanctum::actingAs($me);

    $response = $this->get("/api/v1/rabs/{$rab->id}/pdf");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects another consumer and a draft RAB over the API', function () {
    $rab = snapshotRab(pdfKonsumen());                              // approved, someone else's
    $draftMine = snapshotRab($me = pdfKonsumen(), Bidang::Cufid, RabStatus::Draft);

    Sanctum::actingAs($me);

    $this->get("/api/v1/rabs/{$rab->id}/pdf")->assertForbidden();        // not my project
    $this->get("/api/v1/rabs/{$draftMine->id}/pdf")->assertForbidden();  // my project, still draft
});
