<?php

use App\Enums\DueCondition;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ReceiptResource;
use App\Filament\Resources\ReceiptResource\Pages\ListReceipts;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function receiptUser(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

/** A checked-out project whose checkout term is paid; returns [paid, unpaid]. */
function paidAndUnpaidTerms(): array
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    $paid = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->pay($paid);
    $unpaid = $project->installments()->where('due_condition', DueCondition::Progress50->value)->sole();

    return [$paid->refresh(), $unpaid];
}

// ---------------------------------------------------------------------------
// Visibility — Finance + overseers only
// ---------------------------------------------------------------------------

it('shows the receipt resource only to Finance and overseers', function () {
    foreach (['finance', 'owner', 'direktur'] as $name) {
        $this->actingAs(receiptUser($name));
        expect(ReceiptResource::canViewAny())->toBeTrue("{$name} should see receipts");
    }

    foreach (['manager', 'hr', 'mitra_pembiayaan', 'mandor', 'konsumen'] as $name) {
        $this->actingAs(receiptUser($name));
        expect(ReceiptResource::canViewAny())->toBeFalse("{$name} must not see receipts");
    }
});

// ---------------------------------------------------------------------------
// Invariant — Finance still does NOT see ProjectResource
// ---------------------------------------------------------------------------

it('keeps Finance out of the project resource', function () {
    $this->actingAs(receiptUser('finance'));

    expect(ReceiptResource::canViewAny())->toBeTrue()
        ->and(ProjectResource::canViewAny())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Scope — only paid terms, and they are downloadable
// ---------------------------------------------------------------------------

it('lists only paid installments and offers the download to Finance', function () {
    [$paid, $unpaid] = paidAndUnpaidTerms();
    $finance = receiptUser('finance');
    $this->actingAs($finance);

    Livewire::test(ListReceipts::class)
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$unpaid])
        ->assertTableActionVisible('unduhKuitansi', $paid);

    expect($finance->can('downloadReceipt', $paid))->toBeTrue()
        ->and($finance->can('downloadReceipt', $unpaid))->toBeFalse(); // not paid
});

it('is read-only — no create/edit/delete', function () {
    [$paid] = paidAndUnpaidTerms();
    $this->actingAs(receiptUser('finance'));

    expect(ReceiptResource::canCreate())->toBeFalse()
        ->and(ReceiptResource::canEdit($paid))->toBeFalse()
        ->and(ReceiptResource::canDelete($paid))->toBeFalse()
        ->and(array_keys(ReceiptResource::getPages()))->toBe(['index']);
});

it('streams the receipt PDF from the resource action', function () {
    [$paid] = paidAndUnpaidTerms();
    $this->actingAs(receiptUser('finance'));

    // The action runs and returns a streamed PDF download without halting.
    Livewire::test(ListReceipts::class)
        ->callTableAction('unduhKuitansi', $paid)
        ->assertOk();
});
