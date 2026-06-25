<?php

namespace App\Models;

use App\Enums\SenderType;
use Database\Factories\ConsultationMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message inside a persisted consultation thread (ERD §A.2).
 */
class ConsultationMessage extends Model
{
    /** @use HasFactory<ConsultationMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'consultation_id',
        'sender_type',
        'message',
        'attachment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_type' => SenderType::class,
        ];
    }

    /**
     * The thread this message belongs to.
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
