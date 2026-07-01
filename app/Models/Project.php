<?php

namespace App\Models;

use App\Enums\Bidang;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Models\Concerns\Auditable;
use App\Models\Scopes\BankMitraScope;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A project (ERD §A.2) — the sales/delivery hub a deal flows into.
 *
 * §6.5: the BankMitraScope confines a Mitra Pembiayaan (L4) to projects whose
 * bank_mitra_id is their own account; every other project is invisible to them.
 * contract_value/payment_scheme are financial, so the model is Auditable.
 */
#[ScopedBy(BankMitraScope::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'konsumen_id',
        'consultation_id',
        'manager_id',
        'bidang',
        'title',
        'status',
        'progress_percent',
        'contract_value',
        'payment_scheme',
        'is_financed',
        'bank_mitra_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bidang' => Bidang::class,
            'status' => ProjectStatus::class,
            'payment_scheme' => PaymentScheme::class,
            'progress_percent' => 'decimal:2',
            'contract_value' => 'decimal:2',
            'is_financed' => 'boolean',
        ];
    }

    public function konsumen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'konsumen_id');
    }

    /**
     * The consultation deal this project grew from, if any (Fase 2B bridge).
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * Design versions, latest first.
     */
    public function designs(): HasMany
    {
        return $this->hasMany(Design::class)->orderByDesc('version');
    }

    /**
     * RAB versions, latest first.
     */
    public function rabs(): HasMany
    {
        return $this->hasMany(Rab::class)->orderByDesc('version');
    }

    /**
     * Payment installments, in term order.
     */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('term_no');
    }

    /**
     * The handover record (Berita Acara Serah Terima). One per project; its
     * signed state unlocks the pelunasan installment (Fase 3).
     */
    public function bast(): HasOne
    {
        return $this->hasOne(Bast::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * The Mitra Pembiayaan (L4) account financing this project, if any
     * (dormant until Fase 3/4, ADR-0008).
     */
    public function bankMitra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bank_mitra_id');
    }
}
