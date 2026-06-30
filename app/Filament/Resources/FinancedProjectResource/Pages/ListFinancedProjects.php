<?php

namespace App\Filament\Resources\FinancedProjectResource\Pages;

use App\Filament\Resources\FinancedProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListFinancedProjects extends ListRecords
{
    protected static string $resource = FinancedProjectResource::class;

    // No create action: a Mitra never originates a project (read-only, 2B-8).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
