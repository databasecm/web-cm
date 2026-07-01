<?php

namespace App\Filament\Resources;

use App\Enums\Bidang;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers\BastRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\DesignsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\RabsRelationManager;
use App\Models\Project;
use App\Models\Role;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Internal management surface for projects (Fase 2B-2). Restricted to staff who
 * manage projects — Owner/Direktur and Managers (the list is narrowed to a
 * Manager's bidang). Consumers reach their projects through the Sanctum API; the
 * financing Mitra gets a separate read-only dashboard (2B-8).
 *
 * Projects are created by the deal→project bridge, not here.
 */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'Proyek';

    protected static ?string $modelLabel = 'Proyek';

    protected static ?string $pluralModelLabel = 'Proyek';

    protected static ?int $navigationSort = 15;

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor !== null
            && (in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true) || $actor->isManager());
    }

    public static function canCreate(): bool
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

    /**
     * Narrow the list to a Manager's own bidang; overseers see all.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();

        if ($actor?->isManager() && $actor->bidang !== null) {
            $query->where('bidang', $actor->bidang->value);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            DesignsRelationManager::class,
            RabsRelationManager::class,
            InstallmentsRelationManager::class,
            BastRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'view' => Pages\ViewProject::route('/{record}'),
        ];
    }
}
