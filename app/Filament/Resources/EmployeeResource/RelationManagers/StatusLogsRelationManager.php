<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\EmployeeStatusChangeType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only position/wage history for a worker (Fase 6-4). Append-only, written
 * by EmployeeService when HR changes a jabatan/gaji; never edited here.
 */
class StatusLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusLogs';

    protected static ?string $title = 'Riwayat Jabatan & Gaji';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('effective_date')->label('Berlaku')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('change_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeStatusChangeType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('old_value')->label('Dari')->placeholder('—'),
                Tables\Columns\TextColumn::make('new_value')->label('Ke')->placeholder('—'),
                Tables\Columns\TextColumn::make('author.name')->label('Oleh')->default('Sistem'),
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
