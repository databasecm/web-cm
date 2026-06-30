<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Exceptions\DesignException;
use App\Models\Design;
use App\Models\Project;
use App\Models\User;

/**
 * Design version lifecycle (Fase 2B-2): add a new version, submit it to the
 * consumer, and record the consumer's approval. Authorization is the caller's
 * responsibility (DesignPolicy); this service enforces the state transitions.
 *
 * Approving a design only records who/when — it deliberately does NOT advance the
 * main project status; that happens when the RAB is approved (2B-5).
 */
class DesignService
{
    /**
     * Add the next design version (auto-incremented per project) as a draft.
     *
     * @param  array{file?: string|null, notes?: string|null}  $data
     */
    public function addVersion(Project $project, array $data = []): Design
    {
        $nextVersion = (int) $project->designs()->max('version') + 1;

        return Design::create([
            'project_id' => $project->id,
            'version' => $nextVersion,
            'file' => $data['file'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => DesignStatus::Draft,
        ]);
    }

    /**
     * Submit a draft design to the consumer for approval.
     */
    public function submit(Design $design): Design
    {
        if ($design->status !== DesignStatus::Draft) {
            throw DesignException::notDraft();
        }

        $design->update(['status' => DesignStatus::Submitted]);

        return $design;
    }

    /**
     * Record the consumer's approval of a submitted design. Does not touch the
     * project status (see 2B-5).
     */
    public function approve(Design $design, User $konsumen): Design
    {
        if (! $design->isSubmitted()) {
            throw DesignException::notSubmitted();
        }

        $design->update([
            'status' => DesignStatus::Approved,
            'approved_by' => $konsumen->id,
            'approved_at' => now(),
        ]);

        return $design;
    }
}
