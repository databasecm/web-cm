<?php

namespace App\Notifications;

use App\Models\Payroll;

/**
 * E10 — a weekly payroll run was paid (Fase 7-6). Same strict guard: the body
 * states the period and that it was paid — never the total or any per-worker
 * figure.
 */
class PayrollPaidNotification extends BaseNotification
{
    public function __construct(public Payroll $payroll) {}

    public function event(): string
    {
        return 'payroll.paid';
    }

    public function title(): string
    {
        return 'Payroll telah dibayar';
    }

    public function body(): string
    {
        return "Payroll minggu {$this->period()} telah dibayar.";
    }

    public function entityType(): ?string
    {
        return 'payroll';
    }

    public function entityId(): int|string|null
    {
        return $this->payroll->id;
    }

    public function actionUrl(): ?string
    {
        return url("/payrolls/{$this->payroll->id}");
    }

    private function period(): string
    {
        return "{$this->payroll->period_start->toDateString()} s/d {$this->payroll->period_end->toDateString()}";
    }
}
