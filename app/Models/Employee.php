<?php

namespace App\Models;

use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Models\Concerns\Auditable;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A worker / employee (ERD §A.5).
 *
 * HARD RULE (CLAUDE.md §7): this is a DATA ENTITY, not a login account. It does
 * NOT extend Authenticatable, carries no credentials, and nothing authenticates
 * as an employee. It is used for attendance (by the Mandor) and payroll (by HR).
 * `managed_by` points at the managing Mandor's user account — an attribution,
 * never the employee's identity.
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'bidang',
        'type',
        'daily_wage',
        'position',
        'status',
        'managed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bidang' => Bidang::class,
            'type' => EmployeeType::class,
            'status' => EmployeeStatus::class,
            'daily_wage' => 'decimal:2',
        ];
    }

    /** The managing Mandor (a user account) — attribution, not identity. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(EmployeeStatusLog::class)->orderBy('id');
    }
}
