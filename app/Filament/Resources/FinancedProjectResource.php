<?php

namespace App\Filament\Resources;

use App\Enums\Bidang;
use App\Enums\ProjectStatus;
use App\Filament\Resources\FinancedProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers\InstallmentsRelationManager;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only financing dashboard for a Mitra Pembiayaan (L4, Fase 2B-8). It lists
 * only the projects the bank finances — BankMitraScope already constrains the
 * query to bank_mitra_id = the signed-in Mitra (CLAUDE.md §6.5), so other banks'
 * projects are invisible (not just hidden, but absent from every query).
 *
 * The Mitra never creates, edits or deletes anything here: every mutation gate is
 * closed and the View page only renders read-only infolists. bank_mitra_id is
 * still dormant in 2B (financing lands in Fase 3/4), so this dashboard is empty
 * until a project carries a financing partner.
 */
class FinancedProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Proyek Dibiayai';

    protected static ?string $modelLabel = 'Proyek Dibiayai';

    protected static ?string $pluralModelLabel = 'Proyek Dibiayai';

    protected static ?int $navigationSort = 15;

    /**
     * Only a financing Mitra sees this dashboard. Internal staff and consumers
     * have their own surfaces (ProjectResource / Sanctum API), so this resource
     * stays out of their navigation and is HTTP-blocked for them.
     */
    public static function canViewAny(): bool
    {
        // Only the financing bank, not a Supplier — both are L4 (CLAUDE.md §6.5).
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
                Tables\Columns\TextColumn::make('title')->label('Judul')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('konsumen.name')->label('Konsumen')->searchable(),
                Tables\Columns\TextColumn::make('bidang')
                    ->label('Bidang')
                    ->badge()
                    ->formatStateUsing(fn (?Bidang $state): ?string => $state?->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ProjectStatus $state): string => $state->label())
                    ->color(fn (ProjectStatus $state): string => match ($state) {
                        ProjectStatus::Draft => 'gray',
                        ProjectStatus::Design => 'info',
                        ProjectStatus::Rab => 'warning',
                        ProjectStatus::Active => 'success',
                        ProjectStatus::Done => 'success',
                        ProjectStatus::Cancelled => 'danger',
                    }),
                Tables\Columns\TextColumn::make('progress_percent')->label('Progres')->suffix(' %')->sortable(),
                Tables\Columns\TextColumn::make('contract_value')->label('Nilai Kontrak')->money('IDR')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ProjectStatus::cases())
                        ->mapWithKeys(fn (ProjectStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Buka'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        // Reuse the project's read-only installment schedule (isReadOnly()).
        return [
            InstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancedProjects::route('/'),
            'view' => Pages\ViewFinancedProject::route('/{record}'),
        ];
    }
}
