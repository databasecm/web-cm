<?php

namespace App\Models;

use App\Enums\FinancingDocumentStatus;
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
class FinancingDocument extends Model
{
    /** @use HasFactory<FinancingDocumentFactory> */
    use Auditable, HasFactory;

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
