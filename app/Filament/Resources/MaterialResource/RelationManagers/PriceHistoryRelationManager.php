<?php

namespace App\Filament\Resources\MaterialResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only price timeline for a material (Fase 2A-4). The trail is written by
 * MaterialObserver; it is never edited here.
 */
class PriceHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistory';

    protected static ?string $title = 'Riwayat Harga';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('recorded_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('recorded_at')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('price')->label('Harga')->money('IDR'),
                Tables\Columns\TextColumn::make('changedBy.name')->label('Oleh')->default('Sistem'),
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
