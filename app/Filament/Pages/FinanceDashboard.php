<?php

namespace App\Filament\Pages;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Project;
use App\Models\Role;
use App\Services\FinanceReportService;
use Filament\Pages\Page;

/**
 * Finance dashboard (Fase 6-3) — a read-only snapshot of the cash book: this
 * month's income/expense/result, the all-time cash balance (saldo), and the
 * per-category composition. Same audience as the cash book itself
 * (TransactionPolicy): Finance + Owner/Direktur only.
 *
 * All money is computed by FinanceReportService (BigDecimal-exact); this page is
 * thin glue that formats the strings for display.
 */
class FinanceDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Dashboard Keuangan';

    protected static ?string $title = 'Dashboard Keuangan';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.finance-dashboard';

    public string $periodStart;

    public string $periodEnd;

    /** @var array{income: string, expense: string, net: string, by_category: array<string, string>} */
    public array $period;

    public string $balance;

    /** @var array{projects: array<int, array{income: string, expense: string, net: string}>, unallocated: array{income: string, expense: string, net: string}} */
    public array $projectPnl;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor !== null
            && ($actor->isFinance() || in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true));
    }

    public function mount(FinanceReportService $report): void
    {
        $this->periodStart = now()->startOfMonth()->toDateString();
        $this->periodEnd = now()->endOfMonth()->toDateString();

        $this->period = $report->summary($this->periodStart, $this->periodEnd);
        $this->balance = $report->balance();
        // All-time per-project P&L (unbounded) — a project spans many months.
        $this->projectPnl = $report->profitLossByProject();
    }

    /**
     * Per-project P&L rows (all-time), resolved to project titles and
     * rupiah-formatted, sorted by net descending. Gaji and other unallocated
     * rows are excluded here by design and reported separately.
     *
     * @return array<int, array{title: string, income: string, expense: string, net: string}>
     */
    public function getProjectRows(): array
    {
        $titles = Project::query()
            ->whereIn('id', array_keys($this->projectPnl['projects']))
            ->pluck('title', 'id');

        $projects = $this->projectPnl['projects'];
        // Sort by raw net (string decimals) descending before formatting.
        uasort($projects, fn (array $a, array $b): int => (float) $b['net'] <=> (float) $a['net']);

        $rows = [];
        foreach ($projects as $id => $pnl) {
            $rows[] = [
                'title' => $titles[$id] ?? "Proyek #{$id}",
                'income' => $this->rupiah($pnl['income']),
                'expense' => $this->rupiah($pnl['expense']),
                'net' => $this->rupiah($pnl['net']),
            ];
        }

        return $rows;
    }

    /**
     * The period composition split into income and expense rows, each labelled
     * and rupiah-formatted, for the view.
     *
     * @return array{income: array<int, array{label: string, amount: string}>, expense: array<int, array{label: string, amount: string}>}
     */
    public function getComposition(): array
    {
        $income = [];
        $expense = [];

        foreach ($this->period['by_category'] as $key => $amount) {
            [$type, $category] = explode('.', $key, 2);
            $row = [
                'label' => TransactionCategory::from($category)->label(),
                'amount' => $this->rupiah($amount),
            ];

            if ($type === TransactionType::Income->value) {
                $income[] = $row;
            } else {
                $expense[] = $row;
            }
        }

        return ['income' => $income, 'expense' => $expense];
    }

    public function rupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 2, ',', '.');
    }
}
