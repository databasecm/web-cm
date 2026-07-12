<?php

namespace App\Models;

use App\Enums\FinancingStatus;
use App\Exceptions\FinancingException;
use App\Models\Concerns\Auditable;
use App\Models\Scopes\BankMitraScope;
use Database\Factories\FinancingFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A financing application for a project (ERD §A.4, Fase 4-1). A consumer applies
 * to a bank partner (bank_mitra_id → L4 user account, ADR-0014) to finance a
 * project; the application walks a guarded lifecycle.
 *
 * The BankMitraScope (§6.5) is reused as-is: a bank partner only ever sees the
 * financings whose bank_mitra_id is their own account.
 *
 * Two invariants live here (like BAST):
 * - a project may hold only ONE active (non-final) financing at a time;
 * - status only moves along legal transitions, and every move appends a
 *   financing_status_logs row.
 */
#[ScopedBy(BankMitraScope::class)]
class Financing extends Model
{
    /** @use HasFactory<FinancingFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'project_id',
        'konsumen_id',
        'bank_mitra_id',
        'amount',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FinancingStatus::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Enforce a single active financing per project, across every actor
        // (ignore the bank scope so the check sees all rows).
        static::creating(function (self $financing): void {
            $status = $financing->status instanceof FinancingStatus
                ? $financing->status
                : FinancingStatus::from($financing->status ?? FinancingStatus::Submitted->value);

            if (! $status->isActive()) {
                return;
            }

            $activeExists = self::withoutGlobalScopes()
                ->where('project_id', $financing->project_id)
                ->whereIn('status', FinancingStatus::activeValues())
                ->exists();

            if ($activeExists) {
                throw FinancingException::alreadyActive();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function konsumen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'konsumen_id');
    }

    public function bankMitra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bank_mitra_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(FinancingStatusLog::class)->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FinancingDocument::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Move to a new status along a legal transition, appending a status-log row.
     * Rejects an illegal jump (or any transition out of a final state). The full
     * lifecycle orchestration (who may call this, side effects like disbursement
     * → cash book) lands in Fase 4-2.
     */
    public function transitionTo(FinancingStatus $target, ?int $by = null, ?string $note = null): self
    {
        if (! $this->status->canTransitionTo($target)) {
            throw FinancingException::invalidTransition($this->status, $target);
        }

        return DB::transaction(function () use ($target, $by, $note): self {
            $this->status = $target;
            $this->save();

            $this->statusLogs()->create([
                'status' => $target,
                'note' => $note,
                'created_by' => $by,
            ]);

            return $this;
        });
    }
}
