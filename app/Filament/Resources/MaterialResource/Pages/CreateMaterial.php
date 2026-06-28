<?php

namespace App\Filament\Resources\MaterialResource\Pages;

use App\Filament\Resources\MaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterial extends CreateRecord
{
    protected static string $resource = MaterialResource::class;

    /**
     * Attribute the material to the staff/mandor who entered it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['input_by'] = auth()->id();

        return $data;
    }
}
