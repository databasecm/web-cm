<?php

namespace App\Filament\Resources\AhsapResource\Pages;

use App\Filament\Resources\AhsapResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAhsap extends EditRecord
{
    protected static string $resource = AhsapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
