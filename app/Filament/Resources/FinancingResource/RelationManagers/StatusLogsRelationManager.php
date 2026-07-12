<?php

namespace App\Filament\Resources\FinancingResource\RelationManagers;

use App\Enums\FinancingStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only history of the application's status transitions (Fase 4-4). Written
 * by Financing::transitionTo() — never edited by hand.
 */
class StatusLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusLogs';

    protected static ?string $title = 'Riwayat Status';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (FinancingStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('note')->label('Catatan')->placeholder('—')->limit(50),
                Tables\Columns\TextColumn::make('author.name')->label('Oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
