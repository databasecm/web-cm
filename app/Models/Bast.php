<?php

namespace App\Models;

use App\Contracts\HasMedia;
use App\Enums\BastStatus;
use App\Exceptions\BastException;
use App\Media\MediaDescriptor;
use App\Models\Concerns\Auditable;
use Database\Factories\BastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's BAST — Berita Acara Serah Terima / handover record (ERD §A.4).
 * One per project (unique project_id). It begins as `draft` and may only reach
 * `signed` once BOTH parties have signed.
 *
 * The two-signature invariant is enforced at the persistence layer (see
 * {@see booted()}), so no write path — mass assignment, direct set, or factory —
 * can force a `signed` BAST without both signatures. The full handover flow
 * (issuing the draft, recording each signature, unlocking the pelunasan
 * installment) is wired in Fase 3-2 via BastService.
 */
class Bast extends Model implements HasMedia
{
    /** @use HasFactory<BastFactory> */
    use Auditable, HasFactory;

    protected $table = 'bast';

    /**
     * `bast.file` is the uploaded handover DOCUMENT (a PDF attachment) — distinct
     * from the system-GENERATED BAST PDF (BastPdf, guarded by downloadPdf). The
     * attachment is guarded by the BAST `view` policy (project-scoped) — ADR-0015
     * (Fase media-2).
     */
    public function mediaDescriptor(): MediaDescriptor
    {
        return new MediaDescriptor(
            prefix: 'bast',
            profiles: ['document'],
            viewAbility: 'view',
        );
    }

    /**
     * In-memory defaults matching the DB defaults, so a freshly created BAST
     * (before reload) already reads as an unsigned draft.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'signed_customer' => false,
        'signed_company' => false,
        'status' => 'draft',
    ];

    protected $fillable = [
        'project_id',
        'file',
        'signed_customer',
        'signed_customer_by',
        'signed_company',
        'signed_company_by',
        'signed_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BastStatus::class,
            'signed_customer' => 'boolean',
            'signed_company' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Invariant: a BAST can only be persisted as `signed` when both parties
        // have signed; when it is, stamp signed_at if it is not set yet.
        static::saving(function (self $bast): void {
            if ($bast->status !== BastStatus::Signed) {
                return;
            }

            if (! $bast->bothPartiesSigned()) {
                throw BastException::signaturesRequired();
            }

            if ($bast->signed_at === null) {
                $bast->signed_at = now();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customerSigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_customer_by');
    }

    public function companySigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_company_by');
    }

    public function isSigned(): bool
    {
        return $this->status === BastStatus::Signed;
    }

    public function bothPartiesSigned(): bool
    {
        return $this->signed_customer && $this->signed_company;
    }

    /**
     * Transition to `signed`. Requires both signatures already recorded; the
     * saving guard stamps signed_at. The unlock side effect belongs to
     * BastService (Fase 3-2), not the model.
     */
    public function markSigned(): void
    {
        if (! $this->bothPartiesSigned()) {
            throw BastException::signaturesRequired();
        }

        $this->status = BastStatus::Signed;
        $this->save();
    }
}
