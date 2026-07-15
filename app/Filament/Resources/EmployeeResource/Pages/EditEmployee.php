<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * The plain edit form touches name/type/status only — daily_wage and position
 * are disabled here and change exclusively through the logged "Ubah Gaji" /
 * "Ubah Jabatan" actions (employee_status_logs paper trail).
 */
class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
