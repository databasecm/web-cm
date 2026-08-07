<?php

namespace App\Notifications;

use App\Models\DailyReport;

/**
 * E5 — a Mandor filed a daily field report (Fase 7-4).
 *
 * A pure knock: the body carries NO report text, progress note, photo or
 * caption — only that a report exists. The content sits behind `action_url`,
 * guarded by DailyReportPolicy::view. The Mandor who wrote it is not a
 * recipient (see RecipientResolver).
 */
class DailyReportCreatedNotification extends BaseNotification
{
    public function __construct(public DailyReport $report) {}

    public function event(): string
    {
        return 'daily_report.created';
    }

    public function title(): string
    {
        return 'Laporan harian baru';
    }

    public function body(): string
    {
        return "Laporan harian baru pada proyek #{$this->report->project_id}.";
    }

    public function entityType(): ?string
    {
        return 'daily_report';
    }

    public function entityId(): int|string|null
    {
        return $this->report->id;
    }

    public function actionUrl(): ?string
    {
        return url("/projects/{$this->report->project_id}");
    }
}
