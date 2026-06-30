<?php

namespace App\Enums;

/**
 * Lifecycle of a project (ERD §A.2).
 *
 * draft → design → rab → active → done (or cancelled). A project is created in
 * `draft` straight after a deal (the deal→project bridge), then progresses as a
 * design and RAB are produced and approved, finalised on checkout, and runs to
 * completion.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Design = 'design';
    case Rab = 'rab';
    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Design => 'Desain',
            self::Rab => 'RAB',
            self::Active => 'Berjalan',
            self::Done => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
