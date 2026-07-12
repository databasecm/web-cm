<?php

namespace App\Filament\Resources;

use App\Enums\FinancingStatus;
use App\Filament\Resources\FinancingResource\Pages;
use App\Filament\Resources\FinancingResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\FinancingResource\RelationManagers\StatusLogsRelationManager;
use App\Models\Financing;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Bank portal for financing applications (Fase 4-4). Visible only to a Mitra
 * Pembiayaan (L4); BankMitraScope already confines every query to the bank's own
 * financings, so other banks' applications are absent (Supplier — also L4 — sees
 * nothing, having no financings). Owner/Direktur oversight lives elsewhere.
 *
 * The bank drives the lifecycle here (view page actions) and reviews documents
 * (relation manager), all through FinancingService / FinancingDocumentService —
 * thin surface, no duplicated logic. Nothing here can mutate a project (§6.5):
 * project data is shown as read-only context only.
 */
class FinancingResource extends Resource
{
    protected static ?string $model = Financing::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Pengajuan Pembiayaan';

    protected static ?string $modelLabel = 'Pengajuan Pembiayaan';

    protected static ?string $pluralModelLabel = 'Pengajuan Pembiayaan';

    protected static ?int $navigationSort = 16;

    public static function canViewAny(): bool
    {
        // Only the financing bank, not a Supplier (both are L4).
        return auth()->user()?->isMitraPembiayaan() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('project.title')->label('Proyek')->searchable(),
                Tables\Columns\TextColumn::make('konsumen.name')->label('Konsumen')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Nilai')->money('IDR'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (FinancingStatus $state): string => $state->label())
                    ->color(fn (FinancingStatus $state): string => match ($state) {
                        FinancingStatus::Submitted => 'gray',
                        FinancingStatus::DocsRequired => 'warning',
                        FinancingStatus::Interview => 'info',
                        FinancingStatus::Approved => 'success',
                        FinancingStatus::Rejected => 'danger',
                        FinancingStatus::Disbursed => 'success',
                    }),
                Tables\Columns\TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(FinancingStatus::cases())
                        ->mapWithKeys(fn (FinancingStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Buka'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            StatusLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancings::route('/'),
            'view' => Pages\ViewFinancing::route('/{record}'),
        ];
    }
}
