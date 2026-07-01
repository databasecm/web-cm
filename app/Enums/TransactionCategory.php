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
}
