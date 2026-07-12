<?php

namespace App\Models;

use App\Enums\EmployeeStatusChangeType;
use App\Services\EmployeeService;
use Database\Factories\EmployeeStatusLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in an employee's position/wage history (ERD §A.5). Append-only;
 * written by {@see EmployeeService}.
 */
class EmployeeStatusLog extends Model
{
    /** @use HasFactory<EmployeeStatusLogFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'change_type',
        'old_value',
        'new_value',
        'effective_date',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change_type' => EmployeeStatusChangeType::class,
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
