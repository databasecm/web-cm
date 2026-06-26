<?php

namespace App\Filament\Resources\ConsultationResource\Pages;

use App\Filament\Resources\ConsultationResource;
use Filament\Resources\Pages\ListRecords;

class ListConsultations extends ListRecords
{
    protected static string $resource = ConsultationResource::class;

    // No create header action: consultations originate from the consumer side.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
