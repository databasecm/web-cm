<?php

namespace App\Enums;

/**
 * The two parties that sign a BAST (ERD §A.4): the consumer (via the API) and
 * the company, represented by the Manager of the project's bidang (via Filament).
 */
enum BastParty: string
{
    case Customer = 'customer';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Konsumen',
            self::Company => 'Perusahaan',
        };
    }
}
