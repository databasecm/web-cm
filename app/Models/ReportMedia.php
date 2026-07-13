<?php

namespace App\Models;

use App\Enums\ReportMediaType;
use Database\Factories\ReportMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo/video attached to a daily report (ERD §A.5). `file` is a path/link for
 * now (binary upload deferred, ADR-0015).
 */
class ReportMedia extends Model
{
    /** @use HasFactory<ReportMediaFactory> */
    use HasFactory;

    protected $table = 'report_media';

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
