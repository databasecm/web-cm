<?php

namespace App\Enums;

/**
 * Cash-book category (ERD §A.6). Consumer installment payments land under
 * `pembayaran_konsumen`; the other categories fill in with later modules
 * (investor financing, material/PO, operations, payroll).
 */
enum TransactionCategory: string
{
    case PembayaranKonsumen = 'pembayaran_konsumen';
    case Investor = 'investor';
    case Material = 'material';
    case Operasional = 'operasional';
    case Gaji = 'gaji';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::PembayaranKonsumen => 'Pembayaran Konsumen',
            self::Investor => 'Investor',
            self::Material => 'Material',
            self::Operasional => 'Operasional',
            self::Gaji => 'Gaji',
            self::Lainnya => 'Lainnya',
        };
    }

    /**
     * Categories a human may post BY HAND for a given direction (Fase 6-3).
     *
     * The rest are posted automatically and must never be hand-entered, or the
     * cash book double-counts: pembayaran_konsumen (installment income),
     * gaji (payroll expense) and material (PO expense, Fase 6-5). `investor`
     * income is allowed by hand — a direct capital injection is a real event
     * distinct from a financing disbursement (which tags reference_type
     * `financing`); the manual row is tagged `manual`, so the two never merge.
     *
     * @return list<self>
     */
    public static function manualOptions(TransactionType $type): array
    {
        return match ($type) {
            TransactionType::Income => [self::Investor, self::Lainnya],
            TransactionType::Expense => [self::Operasional, self::Lainnya],
        };
    }

    /**
     * Whether this category may be posted by hand for the given direction.
     */
    public function isManualFor(TransactionType $type): bool
    {
        return in_array($this, self::manualOptions($type), true);
    }
}
