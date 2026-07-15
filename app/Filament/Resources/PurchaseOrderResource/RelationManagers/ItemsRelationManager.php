<?php

namespace App\Filament\Resources\PurchaseOrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only line items for a PO (Fase 6-5), shown on the view page. Items are
 * edited via the PO form's repeater, not here; unit_price is the snapshot taken
 * at creation.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Item';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('description')->label('Deskripsi'),
                Tables\Columns\TextColumn::make('material.name')->label('Material')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('unit')->label('Satuan')->placeholder('—'),
                Tables\Columns\TextColumn::make('quantity')->label('Qty'),
                Tables\Columns\TextColumn::make('unit_price')->label('Harga (snapshot)')->money('IDR'),
                Tables\Columns\TextColumn::make('subtotal')->label('Subtotal')->money('IDR')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->money('IDR')),
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
