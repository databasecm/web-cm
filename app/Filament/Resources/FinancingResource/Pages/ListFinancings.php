<?php

namespace App\Filament\Resources\FinancingResource\Pages;

use App\Filament\Resources\FinancingResource;
use Filament\Resources\Pages\ListRecords;

class ListFinancings extends ListRecords
{
    protected static string $resource = FinancingResource::class;

    // The bank never originates an application (consumers apply via the API).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
