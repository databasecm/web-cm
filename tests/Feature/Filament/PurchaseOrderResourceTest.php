<?php

use App\Enums\Bidang;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TransactionCategory;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function poResUser(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// RBAC — Finance/O-D and Manager see the resource; nobody else
// ---------------------------------------------------------------------------

it('exposes the PO resource to Finance, overseers and Manager only', function () {
    foreach (['finance', 'owner', 'direktur'] as $name) {
        $this->actingAs(poResUser($name));
        expect(PurchaseOrderResource::canViewAny())->toBeTrue("{$name} sees POs");
    }
    $this->actingAs(poResUser('manager', Bidang::Cufid));
    expect(PurchaseOrderResource::canViewAny())->toBeTrue('manager sees POs');

    foreach (['hr', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        $this->actingAs(poResUser($name, Bidang::Cufid));
        expect(PurchaseOrderResource::canViewAny())->toBeFalse("{$name} must not see POs");
    }
});

// ---------------------------------------------------------------------------
// Create through the panel computes the total from line items
// ---------------------------------------------------------------------------

it('creates a PO through the panel and computes the total from items', function () {
    $finance = poResUser('finance');
    $project = Project::factory()->create();
    $this->actingAs($finance);

    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm([
            'project_id' => $project->id,
            'items' => [
                ['material_id' => null, 'description' => 'Semen', 'unit' => 'sak', 'quantity' => '10', 'unit_price' => '50000'],
                ['material_id' => null, 'description' => 'Besi', 'unit' => 'batang', 'quantity' => '4', 'unit_price' => '125000'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $po = PurchaseOrder::sole();
    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->po_number)->toStartWith('PO-')
        ->and((int) $po->ordered_by)->toBe($finance->id)
        ->and($po->items()->count())->toBe(2)
        ->and(BigDecimal::of((string) $po->total)->isEqualTo('1000000.00'))->toBeTrue(); // 500000 + 500000
});

// ---------------------------------------------------------------------------
// SoD in the UI — Manager orders (no receive); Finance receives
// ---------------------------------------------------------------------------

it('shows order to a bidang Manager but hides receive; Finance sees receive', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create();
    $po->items()->create(['description' => 'X', 'unit' => 'unit', 'quantity' => '1.00', 'unit_price' => '1000.00', 'subtotal' => '1000.00']);

    // Manager (own bidang) sees "Pesan" on the draft, never "Terima".
    $this->actingAs(poResUser('manager', Bidang::Cufid));
    Livewire::test(ListPurchaseOrders::class)
        ->assertTableActionVisible('pesan', $po)
        ->assertTableActionHidden('terima', $po);

    // Once ordered, only Finance/O-D see "Terima".
    app(PurchaseOrderService::class)->order($po->fresh(), poResUser('finance'));
    $po->refresh();

    $this->actingAs(poResUser('manager', Bidang::Cufid));
    Livewire::test(ListPurchaseOrders::class)->assertTableActionHidden('terima', $po);

    $this->actingAs(poResUser('finance'));
    Livewire::test(ListPurchaseOrders::class)->assertTableActionVisible('terima', $po);
});

// ---------------------------------------------------------------------------
// Finance receives from the panel → material expense posted, PO final
// ---------------------------------------------------------------------------

it('lets Finance receive from the panel, posting the material expense', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create();
    $po->items()->create(['description' => 'Semen', 'unit' => 'sak', 'quantity' => '10.00', 'unit_price' => '50000.00', 'subtotal' => '500000.00']);
    app(PurchaseOrderService::class)->order($po->fresh(), poResUser('finance'));
    $po->refresh();

    $this->actingAs(poResUser('finance'));
    Livewire::test(ListPurchaseOrders::class)
        ->callTableAction('terima', $po)
        ->assertHasNoTableActionErrors();

    $expense = Transaction::forPurchaseOrders()->where('reference_id', $po->id)->sole();
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($expense->category)->toBe(TransactionCategory::Material)
        ->and((int) $expense->project_id)->toBe($project->id)
        ->and(BigDecimal::of($expense->amount)->isEqualTo('500000.00'))->toBeTrue();
});
