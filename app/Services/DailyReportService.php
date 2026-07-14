<?php

namespace App\Services;

use App\Enums\ReportMediaType;
use App\Exceptions\DailyReportException;
use App\Models\DailyReport;
use App\Models\Project;
use App\Models\ReportMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Daily field reports by the Mandor (Fase 5-3). Pure service + guards;
 * authorization is the caller's job (createDailyReport gate / DailyReportPolicy).
 *
 * IMPORTANT (separation of concerns): a report's progress_note is narrative only.
 * This service NEVER touches project.progress_percent — advancing progress (which
 * unlocks the progress50 term) stays a Manager action via ProgressService
 * (2B-6). A daily report is not a payment trigger.
 */
class DailyReportService
{
    /**
     * File a daily report for a project (one per project per day).
     */
    public function create(Project $project, User $mandor, string $date, string $description, ?string $progressNote = null, ?string $clientId = null): DailyReport
    {
        // Idempotent offline sync: a retried item (same client_id) returns the
        // already-created report (wasRecentlyCreated = false) — no duplicate.
        if ($clientId !== null) {
            $existing = DailyReport::query()->where('client_id', $clientId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($project, $mandor, $date, $description, $progressNote, $clientId): DailyReport {
            $exists = DailyReport::query()
                ->where('project_id', $project->id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw DailyReportException::alreadyExists();
            }

            return DailyReport::create([
                'client_id' => $clientId,
                'project_id' => $project->id,
                'mandor_id' => $mandor->id,
                'date' => $date,
                'description' => $description,
                'progress_note' => $progressNote,
            ]);
        });
    }

    /**
     * Edit an existing report's narrative (mis-entry). project/date are
     * immutable; the change is captured in the audit trail.
     */
    public function update(DailyReport $report, string $description, ?string $progressNote = null): DailyReport
    {
        $report->update([
            'description' => $description,
            'progress_note' => $progressNote,
        ]);

        return $report;
    }

    /**
     * Attach a media item (path/link) to a report.
     */
    public function addMedia(DailyReport $report, ReportMediaType $type, ?string $file = null, ?string $caption = null): ReportMedia
    {
        return $report->media()->create([
            'type' => $type,
            'file' => $file,
            'caption' => $caption,
        ]);
    }
}
