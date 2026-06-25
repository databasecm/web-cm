<?php

namespace App\Models;

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A persisted consultation thread for a logged-in consumer (ERD §A.2).
 *
 * Routing is at the bidang level; `manager_id` is filled only when a Manager
 * first responds (claim model, ADR-0003). Guest (no-login) consultations are
 * never represented by this model — they live only in Redis (ADR-0003).
 */
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use HasFactory;

    protected $fillable = [
        'konsumen_id',
        'manager_id',
        'bidang',
        'is_guest',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bidang' => Bidang::class,
            'is_guest' => 'boolean',
            'status' => ConsultationStatus::class,
        ];
    }

    /**
     * The consumer (level 6) who owns the thread. Null only transiently while a
     * guest-originated thread is being promoted on deal.
     */
    public function konsumen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'konsumen_id');
    }

    /**
     * The Manager who claimed the thread, or null while still unclaimed.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Messages in the thread, chronological.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class);
    }

    /**
     * Whether the thread still accepts new messages / status changes.
     */
    public function isClosed(): bool
    {
        return $this->status === ConsultationStatus::Closed;
    }
}
