<?php

namespace App\Filament\Resources\PayrollResource\RelationManagers;

use App\Models\Payslip;
use App\Services\PayslipPdf;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only payslips within a payroll run (Fase 6-4). Figures are computed at
 * generation (Fase 6-1) and never edited here; HR/Finance view them and may
 * download the slip PDF.
 */
class PayslipsRelationManager extends RelationManager
{
    protected static string $relationship = 'payslips';

    protected static ?string $title = 'Slip Gaji';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')->label('Karyawan')->searchable(),
                Tables\Columns\TextColumn::make('days_present')->label('Hari Hadir'),
                Tables\Columns\TextColumn::make('daily_wage')->label('Upah/Hari')->money('IDR'),
                Tables\Columns\TextColumn::make('gross')->label('Bruto')->money('IDR'),
                Tables\Columns\TextColumn::make('deductions')->label('Potongan')->money('IDR'),
                Tables\Columns\TextColumn::make('net')->label('Net')->money('IDR')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->money('IDR')),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('unduhSlip')
                    ->label('Unduh Slip')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Payslip $record) {
                        $pdf = app(PayslipPdf::class);

                        return response()->streamDownload(
                            fn () => print ($pdf->make($record)->output()),
                            $pdf->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
