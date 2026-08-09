<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One audit row per payment-gateway callback (ADR-0013). Append-only: no
 * updated_at, never mutated. The payload is stored REDACTED (signature material
 * stripped by PaymentWebhookLogger).
 */
class PaymentWebhookLog extends Model
{
    public const OUTCOME_SETTLED = 'settled';

    public const OUTCOME_NOOP = 'noop';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_UNKNOWN_REF = 'unknown_ref';

    public $timestamps = false;

    protected $fillable = [
        'gateway',
        'gateway_ref',
        'transaction_status',
        'verified',
        'outcome',
        'ip',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
