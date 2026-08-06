<?php

namespace App\Models;

use App\Contracts\HasMedia;
use App\Enums\ReportMediaType;
use App\Media\MediaDescriptor;
use Database\Factories\ReportMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo/video attached to a daily report (ERD §A.5). The binary lives on the
 * media disk (ADR-0015, Fase media-3); `type` is derived server-side from the
 * upload's MIME. Viewing is guarded by the parent report's view policy
 * (ReportMediaPolicy delegates to DailyReportPolicy::view).
 */
class ReportMedia extends Model implements HasMedia
{
    /** @use HasFactory<ReportMediaFactory> */
    use HasFactory;

    protected $table = 'report_media';

    /**
     * A report medium may be an image or a video; per-MIME size limits apply
     * (image 5 MB / video 50 MB, from config). Viewing follows the parent report.
     */
    public function mediaDescriptor(): MediaDescriptor
    {
        return new MediaDescriptor(
            prefix: 'report-media',
            profiles: ['image', 'video'],
            viewAbility: 'view',
        );
    }

    protected $fillable = [
        'daily_report_id',
        'type',
        'file',
        'caption',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReportMediaType::class,
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }
}
