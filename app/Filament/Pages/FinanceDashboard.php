<?php

namespace App\Filament\Pages;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
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
