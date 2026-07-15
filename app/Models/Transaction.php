<?php

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Services\PaymentService;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash-book entry (ERD §A.6) — the single source of truth for Finance. Money
 * flows in/out as rows here; consumer installment payments are recorded by
 * {@see PaymentService}. Financial rows are Auditable (§6.6).
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use Auditable, HasFactory;

    /** Logical reference tag for a consumer installment payment. */
    public const REF_INSTALLMENT = 'installment';

    /** Logical reference tag for a financing disbursement. */
    public const REF_FINANCING = 'financing';

    /** Logical reference tag for a payroll payout. */
    public const REF_PAYROLL = 'payroll';

    /** Logical reference tag for a hand-entered (manual) cash-book row. */
    public const REF_MANUAL = 'manual';

    protected $fillable = [
        'type',
        'category',
        'amount',
        'reference_type',
        'reference_id',
        'project_id',
        'description',
        'recorded_by',
        'date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'category' => TransactionCategory::class,
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The project this cash movement belongs to (null = unallocated, e.g. gaji). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeForInstallments(Builder $query): Builder
    {
        return $query->where('reference_type', self::REF_INSTALLMENT);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeForFinancings(Builder $query): Builder
    {
        return $query->where('reference_type', self::REF_FINANCING);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeForPayrolls(Builder $query): Builder
    {
        return $query->where('reference_type', self::REF_PAYROLL);
    }

    /**
     * A hand-entered row (reference_type = manual) — the only kind Finance may
     * edit or delete. Auto-sourced rows (installment/financing/payroll/PO) mirror
     * real events and stay read-only in the cash book.
     */
    public function isManual(): bool
    {
        return $this->reference_type === self::REF_MANUAL;
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('reference_type', self::REF_MANUAL);
    }
}
