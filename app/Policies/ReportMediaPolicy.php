<?php

namespace App\Policies;

use App\Models\ReportMedia;
use App\Models\User;

/**
 * Authorization for a daily report's media (Fase media-3, ADR-0015). A report
 * medium is only as visible as its parent report — this delegates to
 * DailyReportPolicy::view (internal bidang staff, the owning consumer, and the
 * project's financing bank read-only). There is no separate write ability:
 * uploads are authorized against the report (update) at the API.
 */
class ReportMediaPolicy
{
    public function view(User $actor, ReportMedia $media): bool
    {
        $report = $media->report;

        return $report !== null && $actor->can('view', $report);
    }
}
