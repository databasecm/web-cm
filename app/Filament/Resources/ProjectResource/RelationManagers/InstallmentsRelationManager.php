<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Services\PaymentReceiptPdf;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view of a project's installment schedule and unlock state (Fase
 * 2B-6). Terms are generated at checkout and opened by progress/BAST rules — they
 * are never edited by hand here.
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Termin';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('term_no')
            ->columns([
                Tables\Columns\TextColumn::make('term_no')->label('#'),
                Tables\Columns\TextColumn::make('label')->label('Termin'),
                Tables\Columns\TextColumn::make('percentage')->label('%')->formatStateUsing(fn ($state): string => rtrim(rtrim((string) $state, '0'), '.').'%'),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR'),
                Tables\Columns\TextColumn::make('due_condition')
                    ->label('Syarat')
                    ->badge()
                    ->formatStateUsing(fn (DueCondition $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (InstallmentStatus $state): string => $state->label())
                    ->color(fn (InstallmentStatus $state): string => match ($state) {
                        InstallmentStatus::Locked => 'gray',
                        InstallmentStatus::Unlocked => 'warning',
                        InstallmentStatus::Paid => 'success',
                    }),
            ])
            ->headerActions([])
            ->actions([
                // Download the payment receipt (kuitansi) — only for a paid term,
                // gated by InstallmentPolicy::downloadReceipt (Finance/O/D here).
                Tables\Actions\Action::make('unduhKuitansi')
                    ->label('Unduh Kuitansi')
                    ->icon('heroicon-o-receipt-percent')
                    ->visible(fn (Installment $record): bool => auth()->user()->can('downloadReceipt', $record))
                    ->action(function (Installment $record) {
                        $pdf = app(PaymentReceiptPdf::class);

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
