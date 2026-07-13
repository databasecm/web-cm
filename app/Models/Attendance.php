<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worker's attendance for one day (ERD §A.5). The source of truth for daily
 * payroll (Fase 6), so it is Auditable. One row per worker per day
 * (unique employee_id + date).
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id',
        'project_id',
        'date',
        'status',
        'recorded_by',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
