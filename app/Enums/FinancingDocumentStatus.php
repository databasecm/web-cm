<?php

namespace App\Enums;

/**
 * Review state of a financing document (ERD §A.4):
 * - pending  : uploaded by the consumer, awaiting the bank's review.
 * - accepted : the bank accepted it.
 * - rejected : the bank rejected it (with a reason in `note`).
 */
enum FinancingDocumentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Accepted => 'Diterima',
            self::Rejected => 'Ditolak',
        };
    }
}
