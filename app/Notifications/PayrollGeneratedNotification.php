<?php

namespace App\Notifications;

use App\Models\Payroll;

/**
 * E9 — a weekly payroll run was generated and is ready to pay (Fase 7-6).
 *
 * Strictest money guard in the system: per-worker pay is the most private data,
 * so the body carries ONLY the period and a ready status — never a total, a
 * worker figure, or any amount. The numbers live in the PayrollResource (6-4)
 * behind the policy-guarded action_url.
 */
class PayrollGeneratedNotification extends BaseNotification
{
    public function __construct(public Payroll $payroll) {}

    public function event(): string
    {
        return 'payroll.generated';
    }

    public function title(): string
    {
        return 'Payroll siap dibayar';
    }

    public function body(): string
    {
        return "Payroll minggu {$this->period()} siap.";
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

    /** The Mon–Sat period as plain dates (a period, never money). */
    private function period(): string
    {
        return "{$this->payroll->period_start->toDateString()} s/d {$this->payroll->period_end->toDateString()}";
    }
}
