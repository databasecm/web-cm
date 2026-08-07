<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\RabStatus;
use App\Exceptions\RabException;
use App\Models\Rab;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\RabFinalizedNotification;
use Illuminate\Support\Facades\DB;

/**
 * RAB lifecycle and the single finalisation control point (Fase 2B-5).
 *
 * Approval is where the contract is frozen: the approved RAB's grand_total is
 * snapshotted onto the project as contract_value (layer-3 of ADR-0004/0007), the
 * project status advances, and any previously approved RAB is marked superseded
 * — never silently dropped. The contract_value change is audited via the
 * project's Auditable trail.
 */
class RabService
{
    public function __construct(private NotificationDispatcher $notifications) {}

    /**
     * Submit a draft RAB to the consumer for approval.
     */
    public function submit(Rab $rab): Rab
    {
        if ($rab->status !== RabStatus::Draft) {
            throw RabException::notDraft();
        }

        $rab->update(['status' => RabStatus::Submitted]);

        return $rab;
    }

    /**
     * Approve a submitted RAB (by the consumer) and finalise it as the contract.
     */
    public function approve(Rab $rab, User $konsumen): Rab
    {
        if ($rab->status !== RabStatus::Submitted) {
            throw RabException::notSubmitted();
        }

        $rab = DB::transaction(function () use ($rab): Rab {
            $project = $rab->project;

            // A newly approved revision supersedes the prior approved RAB — the
            // latest approved RAB is the contract; older ones are recorded as
            // superseded, never dropped silently.
            Rab::query()
                ->where('project_id', $project->id)
                ->where('status', RabStatus::Approved->value)
                ->whereKeyNot($rab->getKey())
                ->update(['status' => RabStatus::Superseded->value]);

            $rab->update(['status' => RabStatus::Approved]);

            // Layer-3 snapshot: freeze the contract price on the project.
            $project->forceFill([
                'contract_value' => $rab->grand_total,
                'status' => ProjectStatus::Rab,
            ])->save();

            return $rab;
        });

        // E12 — the consumer and the bidang's Manager; NO RAB total in the body,
        // and Finance is off-map (their concern is the payment, not the contract).
        // After commit; a notification failure never unwinds the approval.
        $this->notifications->dispatch(
            'rab.finalized',
            $rab,
            fn (): RabFinalizedNotification => new RabFinalizedNotification($rab),
        );

        return $rab;
    }
}
