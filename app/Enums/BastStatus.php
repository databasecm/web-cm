<?php

namespace App\Enums;

/**
 * Lifecycle of a project's BAST — Berita Acara Serah Terima (ERD §A.4):
 * - draft  : issued, awaiting both signatures.
 * - signed : both parties (consumer + company) have signed; this state unlocks
 *            the pelunasan installment (wired in Fase 3-2, CLAUDE.md §7).
 */
enum BastStatus: string
{
    case Draft = 'draft';
    case Signed = 'signed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Signed => 'Ditandatangani',
        };
    }
}
