<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * A Mandor's new worker is pinned to the Mandor's own bidang and attributed
     * to that Mandor account (the bidang field is disabled for them in the form).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();

        if ($actor !== null && $actor->isMandor()) {
            $data['bidang'] = $actor->bidang?->value;
            $data['managed_by'] = $actor->id;
        }

        return $data;
    }
}
