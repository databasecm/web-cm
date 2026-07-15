<?php

namespace App\Filament\Resources\PayrollResource\Pages;

use App\Enums\PayrollStatus;
use App\Enums\PayrollType;
use App\Filament\Resources\PayrollResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPayroll extends ViewRecord
{
    protected static string $resource = PayrollResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Payroll')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('period_start')->label('Mulai')->date('d/m/Y'),
                    Infolists\Components\TextEntry::make('period_end')->label('Selesai')->date('d/m/Y'),
                    Infolists\Components\TextEntry::make('type')->label('Tipe')
                        ->formatStateUsing(fn (PayrollType $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (PayrollStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('paid_at')->label('Dibayar')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
        ]);
    }
}
