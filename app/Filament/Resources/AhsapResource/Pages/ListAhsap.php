<?php

namespace App\Filament\Resources\AhsapResource\Pages;

use App\Filament\Resources\AhsapResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAhsap extends ListRecords
{
    protected static string $resource = AhsapResource::class;

    protected function getHeaderActions(): array
    {
        // Only rendered when the policy allows creating (Owner/Direktur/Manager).
        return [
            Actions\CreateAction::make(),
        ];
    }
}
