<?php

namespace App\Models;

use App\Contracts\HasMedia;
use App\Enums\DesignStatus;
use App\Media\MediaDescriptor;
use App\Models\Concerns\Auditable;
use Database\Factories\DesignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a project's design (ERD §A.2). Versioned per project; only a
 * submitted version may be approved by the consumer (Fase 2B-2). Approval merely
 * records who/when — the main project status only advances when the RAB is
 * approved (2B-5).
 */
class Design extends Model implements HasMedia
{
    /** @use HasFactory<DesignFactory> */
    use Auditable, HasFactory;

    /**
     * A design file may be an image or a PDF; viewing it is guarded by the
     * design `view` policy (project-scoped) — ADR-0015 (Fase media-1).
     */
    public function mediaDescriptor(): MediaDescriptor
    {
        return new MediaDescriptor(
            prefix: 'designs',
            profiles: ['image', 'document'],
            viewAbility: 'view',
        );
    }

    protected $fillable = [
        'project_id',
        'version',
        'file',
        'status',
        'notes',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DesignStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isSubmitted(): bool
    {
        return $this->status === DesignStatus::Submitted;
    }
}
