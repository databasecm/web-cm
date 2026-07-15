<?php

use App\Enums\Bidang;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\CreateTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function trxUser(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// ---------------------------------------------------------------------------
// RBAC — the resource & dashboard are visible only to Finance / overseers
// ---------------------------------------------------------------------------

it('shows the cash book & finance dashboard only to Finance and overseers', function () {
    foreach (['finance', 'owner', 'direktur'] as $name) {
        $this->actingAs(trxUser($name));
        expect(TransactionResource::canViewAny())->toBeTrue("{$name} sees the cash book")
            ->and(FinanceDashboard::canAccess())->toBeTrue("{$name} sees the dashboard");
    }

    foreach (['hr', 'manager', 'mandor', 'mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $actor = $name === 'manager'
            ? User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id'), 'bidang' => Bidang::Cufid])
            : trxUser($name);
        $this->actingAs($actor);
        expect(TransactionResource::canViewAny())->toBeFalse("{$name} must not see the cash book")
            ->and(FinanceDashboard::canAccess())->toBeFalse("{$name} must not see the dashboard");
    }
});

// ---------------------------------------------------------------------------
// Creating through the panel routes via the service (manual tag + attribution)
// ---------------------------------------------------------------------------

it('creates a manual entry through the panel, tagged manual and attributed', function () {
    $finance = trxUser('finance');
    $this->actingAs($finance);

    Livewire::test(CreateTransaction::class)
        ->fillForm([
            'type' => TransactionType::Expense->value,
            'category' => TransactionCategory::Operasional->value,
            'amount' => '75000',
            'date' => '2026-07-10',
            'description' => 'Ongkos kirim',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $txn = Transaction::sole();
    expect($txn->isManual())->toBeTrue()
        ->and($txn->reference_type)->toBe(Transaction::REF_MANUAL)
        ->and((int) $txn->recorded_by)->toBe($finance->id)
        ->and($txn->category)->toBe(TransactionCategory::Operasional);
});

// ---------------------------------------------------------------------------
// Auto-sourced rows are read-only in the table; manual rows are editable
// ---------------------------------------------------------------------------

it('hides edit/delete on auto-sourced rows and offers them on manual rows', function () {
    $finance = trxUser('finance');
    $this->actingAs($finance);

    $auto = Transaction::factory()->create(['reference_type' => Transaction::REF_PAYROLL, 'reference_id' => 1]);
    $manual = app(TransactionService::class)->recordManual(
        TransactionType::Expense, TransactionCategory::Operasional, '100', '2026-07-10', null, $finance
    );

    Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$auto, $manual])
        ->assertTableActionHidden('edit', $auto)
        ->assertTableActionHidden('delete', $auto)
        ->assertTableActionVisible('edit', $manual)
        ->assertTableActionVisible('delete', $manual);
});

// ---------------------------------------------------------------------------
// The type filter narrows the book
// ---------------------------------------------------------------------------

it('filters the cash book by type', function () {
    $finance = trxUser('finance');
    $this->actingAs($finance);

    $svc = app(TransactionService::class);
    $income = $svc->recordManual(TransactionType::Income, TransactionCategory::Investor, '500', '2026-07-10', null, $finance);
    $expense = $svc->recordManual(TransactionType::Expense, TransactionCategory::Operasional, '300', '2026-07-10', null, $finance);

    Livewire::test(ListTransactions::class)
        ->filterTable('type', TransactionType::Income->value)
        ->assertCanSeeTableRecords([$income])
        ->assertCanNotSeeTableRecords([$expense]);
});

// ---------------------------------------------------------------------------
// The Finance dashboard renders per-project P&L and the unallocated overhead
// ---------------------------------------------------------------------------

it('renders the finance dashboard with per-project P&L and unallocated overhead', function () {
    $finance = trxUser('finance');
    $project = Project::factory()->create(['title' => 'Proyek Uji PNL']);
    // One project-linked income and one unallocated gaji expense.
    Transaction::factory()->create([
        'type' => TransactionType::Income, 'category' => TransactionCategory::PembayaranKonsumen,
        'amount' => '500000.00', 'project_id' => $project->id,
    ]);
    Transaction::factory()->create([
        'type' => TransactionType::Expense, 'category' => TransactionCategory::Gaji,
        'amount' => '100000.00', 'project_id' => null,
    ]);

    $this->actingAs($finance);
    Livewire::test(FinanceDashboard::class)
        ->assertOk()
        ->assertSee('Proyek Uji PNL')            // per-project row
        ->assertSee('Overhead Tak Teralokasi');  // gaji kept separate
});
