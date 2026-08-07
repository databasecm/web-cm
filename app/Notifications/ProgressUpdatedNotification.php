<?php

namespace App\Notifications;

use App\Models\Project;

/**
 * E4 — a project's progress advanced (Fase 7-4). The percentage is progress,
 * not money, so it may appear in the body.
 */
class ProgressUpdatedNotification extends BaseNotification
{
    public function __construct(public Project $project) {}

    public function event(): string
    {
        return 'progress.updated';
    }

    public function title(): string
    {
        return 'Progres proyek diperbarui';
    }

    public function body(): string
    {
        return "Progres proyek #{$this->project->id} kini {$this->percent()}%.";
    }

    public function entityType(): ?string
    {
        return 'project';
    }

    public function entityId(): int|string|null
    {
        return $this->project->id;
    }

    public function actionUrl(): ?string
    {
        return url("/projects/{$this->project->id}");
    }

    /** Progress without trailing zeros: "50.00" → "50", "12.50" → "12.5". */
    private function percent(): string
    {
        $value = rtrim(rtrim((string) $this->project->progress_percent, '0'), '.');

        return $value === '' ? '0' : $value;
    }
}
