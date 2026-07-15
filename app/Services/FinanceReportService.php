<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the cash book for the Finance dashboard (Fase 6-3). All arithmetic is
 * BigDecimal-exact (ADR-0005) — never float — and rounded to 2 decimals only at
 * the boundary.
 *
 * `net` is income − expense: over a period it is the period result; over all
 * time it is the running cash balance (saldo).
 */
class FinanceReportService
{
    /**
     * Totals and per-category composition for an optional inclusive date range.
     * Omitting a bound leaves that side open (both omitted = all time = saldo).
     *
     * @return array{
     *     income: string,
     *     expense: string,
     *     net: string,
     *     by_category: array<string, string>
     * }
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $income = BigDecimal::zero();
        $expense = BigDecimal::zero();
        /** @var array<string, BigDecimal> $byCategory */
        $byCategory = [];

        foreach ($this->query($from, $to)->get(['type', 'category', 'amount']) as $row) {
            $amount = BigDecimal::of((string) $row->amount);

            if ($row->type === TransactionType::Income) {
                $income = $income->plus($amount);
            } else {
                $expense = $expense->plus($amount);
            }

            $key = $row->type->value.'.'.$row->category->value;
            $byCategory[$key] = ($byCategory[$key] ?? BigDecimal::zero())->plus($amount);
        }

        return [
            'income' => $this->fmt($income),
            'expense' => $this->fmt($expense),
            'net' => $this->fmt($income->minus($expense)),
            'by_category' => array_map(fn (BigDecimal $v): string => $this->fmt($v), $byCategory),
        ];
    }

    /**
     * The all-time running cash balance (income − expense across every row).
     */
    public function balance(): string
    {
        return $this->summary()['net'];
    }

    /**
     * Profit & loss per project (Fase 6-3b): income − expense for rows linked to
     * a project. Rows with no project (project_id NULL) are NOT attributed to any
     * project — most importantly salary (gaji), which spans many projects and so
     * is unallocated overhead. They are returned separately in `unallocated` so
     * the per-project figures never absorb overhead and mislead.
     *
     * @return array{
     *     projects: array<int, array{income: string, expense: string, net: string}>,
     *     unallocated: array{income: string, expense: string, net: string}
     * }
     */
    public function profitLossByProject(?string $from = null, ?string $to = null): array
    {
        /** @var array<int, array{income: BigDecimal, expense: BigDecimal}> $projects */
        $projects = [];
        $unallocIncome = BigDecimal::zero();
        $unallocExpense = BigDecimal::zero();

        foreach ($this->query($from, $to)->get(['type', 'amount', 'project_id']) as $row) {
            $amount = BigDecimal::of((string) $row->amount);
            $isIncome = $row->type === TransactionType::Income;

            if ($row->project_id === null) {
                if ($isIncome) {
                    $unallocIncome = $unallocIncome->plus($amount);
                } else {
                    $unallocExpense = $unallocExpense->plus($amount);
                }

                continue;
            }

            $pid = (int) $row->project_id;
            $projects[$pid] ??= ['income' => BigDecimal::zero(), 'expense' => BigDecimal::zero()];
            $projects[$pid][$isIncome ? 'income' : 'expense'] = $projects[$pid][$isIncome ? 'income' : 'expense']->plus($amount);
        }

        return [
            'projects' => array_map(fn (array $p): array => [
                'income' => $this->fmt($p['income']),
                'expense' => $this->fmt($p['expense']),
                'net' => $this->fmt($p['income']->minus($p['expense'])),
            ], $projects),
            'unallocated' => [
                'income' => $this->fmt($unallocIncome),
                'expense' => $this->fmt($unallocExpense),
                'net' => $this->fmt($unallocIncome->minus($unallocExpense)),
            ],
        ];
    }

    /**
     * @return Builder<Transaction>
     */
    private function query(?string $from, ?string $to): Builder
    {
        $query = Transaction::query();

        if ($from !== null) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('date', '<=', $to);
        }

        return $query;
    }

    private function fmt(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HALF_UP);
    }
}
