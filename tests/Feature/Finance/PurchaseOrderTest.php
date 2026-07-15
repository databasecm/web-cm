<?php

use App\Enums\Bidang;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\PurchaseOrderException;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->po = app(PurchaseOrderService::class);
});

function poRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

/** A draft PO on `$project` with two lines: 10×50000 + 4×125000 = 1_000_000. */
function draftPo(Project $project): PurchaseOrder
{
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create(['total' => '0.00']);
    $po->items()->create(['description' => 'Semen', 'unit' => 'sak', 'quantity' => '10.00', 'unit_price' => '50000.00', 'subtotal' => '0.00']);
    $po->items()->create(['description' => 'Besi', 'unit' => 'batang', 'quantity' => '4.00', 'unit_price' => '125000.00', 'subtotal' => '0.00']);

    return $po;
}

// ---------------------------------------------------------------------------
// Lifecycle: draft → ordered → received posts the material expense
// ---------------------------------------------------------------------------

it('posts a project-linked material expense equal to the PO total on receive', function () {
    $finance = poRoled('finance');
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $po = draftPo($project);

    $this->po->order($po, $finance);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and(BigDecimal::of((string) $po->fresh()->total)->isEqualTo('1000000.00'))->toBeTrue();

    $txn = $this->po->receive($po, $finance);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($po->fresh()->received_at)->not->toBeNull()
        ->and($txn->type)->toBe(TransactionType::Expense)
        ->and($txn->category)->toBe(TransactionCategory::Material)
        ->and($txn->reference_type)->toBe(Transaction::REF_PO)
        ->and((int) $txn->project_id)->toBe($project->id)
        ->and(BigDecimal::of($txn->amount)->isEqualTo('1000000.00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Expense ONLY at received — draft/ordered post nothing
// ---------------------------------------------------------------------------

it('posts no expense while the PO is draft or ordered', function () {
    $po = draftPo(Project::factory()->create());
    expect(Transaction::forPurchaseOrders()->count())->toBe(0);

    $this->po->order($po, poRoled('finance'));
    expect(Transaction::forPurchaseOrders()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Idempotent — a second receive is refused, no duplicate expense
// ---------------------------------------------------------------------------

it('refuses a second receive and never doubles the material expense', function () {
    $finance = poRoled('finance');
    $po = draftPo(Project::factory()->create());
    $this->po->order($po, $finance);
    $this->po->receive($po, $finance);

    expect(fn () => $this->po->receive($po->fresh(), $finance))->toThrow(PurchaseOrderException::class);
    expect(Transaction::forPurchaseOrders()->where('reference_id', $po->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Final POs are immutable; illegal transitions are refused
// ---------------------------------------------------------------------------

it('treats received and cancelled POs as final', function () {
    $finance = poRoled('finance');
    $owner = poRoled('owner');

    $received = draftPo(Project::factory()->create());
    $this->po->order($received, $finance);
    $this->po->receive($received, $finance);

    expect($owner->can('update', $received->fresh()))->toBeFalse()
        ->and(fn () => $this->po->order($received->fresh(), $finance))->toThrow(PurchaseOrderException::class)
        ->and(fn () => $this->po->cancel($received->fresh(), $finance))->toThrow(PurchaseOrderException::class);

    $cancelled = draftPo(Project::factory()->create());
    $this->po->cancel($cancelled, $finance);
    expect($cancelled->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($owner->can('update', $cancelled))->toBeFalse()
        ->and(fn () => $this->po->receive($cancelled->fresh(), $finance))->toThrow(PurchaseOrderException::class);
});

// ---------------------------------------------------------------------------
// unit_price is a snapshot — a later material price move does not touch the PO
// ---------------------------------------------------------------------------

it('snapshots the unit price so a later material price change never moves the PO', function () {
    $finance = poRoled('finance');
    $project = Project::factory()->create();
    $material = Material::factory()->create(['price' => '50000.00', 'name' => 'Semen', 'unit' => 'sak']);

    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create(['total' => '0.00']);
    $po->items()->create([
        'material_id' => $material->id, 'description' => 'Semen', 'unit' => 'sak',
        'quantity' => '10.00', 'unit_price' => '50000.00', 'subtotal' => '0.00',
    ]);
    $this->po->order($po, $finance); // total = 500000

    // Material price doubles AFTER the PO is placed.
    $material->update(['price' => '100000.00']);

    $txn = $this->po->receive($po, $finance);

    expect(BigDecimal::of((string) $po->fresh()->items()->first()->unit_price)->isEqualTo('50000.00'))->toBeTrue()
        ->and(BigDecimal::of((string) $po->fresh()->total)->isEqualTo('500000.00'))->toBeTrue()
        ->and(BigDecimal::of($txn->amount)->isEqualTo('500000.00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// RBAC / SoD — Manager drafts+orders own bidang; only Finance/O-D receive
// ---------------------------------------------------------------------------

it('lets a bidang Manager draft & order but never receive; Finance receives', function () {
    $manager = poRoled('manager', Bidang::Cufid);
    $otherManager = poRoled('manager', Bidang::Cc);
    $finance = poRoled('finance');

    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $po = draftPo($project); // draft

    // Own-bidang Manager: may view/create/order/cancel, but NOT receive.
    expect($manager->can('view', $po))->toBeTrue()
        ->and($manager->can('create', PurchaseOrder::class))->toBeTrue()
        ->and($manager->can('order', $po))->toBeTrue()
        ->and($manager->can('cancel', $po))->toBeTrue();

    $this->po->order($po, $manager);
    expect($manager->can('receive', $po->fresh()))->toBeFalse()   // SoD: Manager never receives
        ->and($finance->can('receive', $po->fresh()))->toBeTrue(); // Finance does

    // A Manager from another bidang sees nothing of this PO.
    expect($otherManager->can('view', $po->fresh()))->toBeFalse()
        ->and($otherManager->can('order', $po->fresh()))->toBeFalse();

    // Outsiders have no access at all.
    foreach (['hr', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        expect(poRoled($name, Bidang::Cufid)->can('viewAny', PurchaseOrder::class))->toBeFalse("{$name} must not see POs");
    }
});
