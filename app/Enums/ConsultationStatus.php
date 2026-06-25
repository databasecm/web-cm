<?php

namespace App\Enums;

/**
 * Lifecycle of a consultation thread.
 *
 * - Open   : routed to a bidang, awaiting / in conversation.
 * - Deal   : the consultation converted into a deal (a project will follow).
 * - Closed : conversation ended without (or after) a deal; read-only.
 */
enum ConsultationStatus: string
{
    case Open = 'open';
    case Deal = 'deal';
    case Closed = 'closed';

    /**
     * Human-readable label (Indonesian UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Deal => 'Deal',
            self::Closed => 'Ditutup',
        };
    }
}
