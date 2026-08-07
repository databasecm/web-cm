<?php

namespace App\Services;

use App\Enums\ReportMediaType;
use App\Exceptions\DailyReportException;
use App\Models\DailyReport;
use App\Models\Project;
use App\Models\ReportMedia;
use App\Models\User;
use App\Notifications\DailyReportCreatedNotification;
use App\Notifications\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
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
    public function __construct(private NotificationDispatcher $notifications) {}

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

        $report = DB::transaction(function () use ($project, $mandor, $date, $description, $progressNote, $clientId): DailyReport {
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

        // E5 — a KNOCK only (no report text/photo in the body); fire once, only
        // for a genuinely new report (a re-synced duplicate returns early above).
        if ($report->wasRecentlyCreated) {
            $this->notifications->dispatch(
                'daily_report.created',
                $report,
                fn (): DailyReportCreatedNotification => new DailyReportCreatedNotification($report),
            );
        }

        return $report;
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
     * Attach a photo/video to a report (Fase media-3, ADR-0015). The binary is
     * validated + stored by MediaService (server-side type & size); `type` is
     * derived from the resulting MIME — never the client's claim.
     */
    public function attachMedia(DailyReport $report, UploadedFile $file, ?string $caption = null): ReportMedia
    {
        $media = new ReportMedia;
        $key = app(MediaService::class)->store($media, $file);

        $mime = $file->getMimeType() ?? '';
        $type = in_array($mime, (array) config('media.profiles.video.mimes', []), true)
            ? ReportMediaType::Video
            : ReportMediaType::Photo;

        return $report->media()->create([
            'type' => $type,
            'file' => $key,
            'caption' => $caption,
        ]);
    }
}
