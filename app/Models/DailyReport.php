<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\DailyReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Mandor's daily field report for a project (ERD §A.5). One per project per
 * day. Auditable. `progress_note` is narrative only — it does NOT advance
 * project.progress_percent and never unlocks a payment term (that stays a
 * Manager action via ProgressService, 2B-6).
 */
class DailyReport extends Model
{
    /** @use HasFactory<DailyReportFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'project_id',
        'mandor_id',
        'date',
        'description',
        'progress_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function mandor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mandor_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ReportMedia::class)->orderBy('id');
    }
}
