<?php

namespace App\Models;

use App\Contracts\HasMedia;
use App\Enums\FinancingDocumentStatus;
use App\Media\MediaDescriptor;
use App\Models\Concerns\Auditable;
use Database\Factories\FinancingDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting document of a financing application (ERD §A.4). Uploaded by the
 * consumer, reviewed by the bank. Financial/sensitive, so Auditable — and `file`
 * is hidden so the document pointer is redacted in the audit trail and never
 * leaks through generic serialization.
 */
class FinancingDocument extends Model implements HasMedia
{
    /** @use HasFactory<FinancingDocumentFactory> */
    use Auditable, HasFactory;

    /**
     * The most sensitive media in the system (KTP/payslip): an image or a PDF,
     * served ONLY to whoever may view the document — the owning consumer, the
     * owning bank, and Owner/Direktur (FinancingDocumentPolicy::view). Never a
     * Manager, never Finance, never another bank/consumer. ADR-0015, Fase media-4.
     */
    public function mediaDescriptor(): MediaDescriptor
    {
        return new MediaDescriptor(
            prefix: 'financing-documents',
            profiles: ['image', 'document'],
            viewAbility: 'view',
        );
    }

    protected $fillable = [
        'financing_id',
        'name',
        'file',
        'status',
        'note',
        'uploaded_by',
        'reviewed_by',
        'reviewed_at',
    ];

    /** The file pointer is sensitive (KTP/payslip): redact in audit, hide in JSON. */
    protected $hidden = ['file'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FinancingDocumentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function financing(): BelongsTo
    {
        return $this->belongsTo(Financing::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
