<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail entry for account & finance mutations (RBAC rule #6).
 */
class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'entity',
        'entity_id',
        'before',
        'after',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * The account that performed the action (null for system actions).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
