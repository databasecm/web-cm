<?php

namespace App\Enums;

/**
 * Lifecycle of a financing application (ERD §A.4, Fase 4-1):
 *
 *   submitted ─┬─▶ docs_required ⇄ interview ─▶ approved ─▶ disbursed
 *              ├──────────────────┴────────────┴─▶ rejected
 *              └─▶ interview / approved / rejected (shortcuts)
 *
 * docs_required and interview may bounce back and forth: after an interview the
 * bank can still ask for more documents (interview → docs_required). `rejected`
 * and `disbursed` are terminal — no further transition.
 */
enum FinancingStatus: string
{
    case Submitted = 'submitted';
    case DocsRequired = 'docs_required';
    case Interview = 'interview';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disbursed = 'disbursed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Diajukan',
            self::DocsRequired => 'Perlu Dokumen',
            self::Interview => 'Wawancara',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Disbursed => 'Dicairkan',
        };
    }

    /** Terminal states never transition again. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Disbursed], true);
    }

    /** A project may hold only one active (non-final) financing at a time. */
    public function isActive(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * The states this one may transition to.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::DocsRequired, self::Interview, self::Approved, self::Rejected],
            self::DocsRequired => [self::Interview, self::Approved, self::Rejected],
            self::Interview => [self::DocsRequired, self::Approved, self::Rejected],
            self::Approved => [self::Disbursed],
            self::Rejected, self::Disbursed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Value list of the active (non-final) states — used to enforce a single
     * active financing per project.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $s): string => $s->value,
            array_filter(self::cases(), fn (self $s): bool => $s->isActive()),
        ));
    }
}
