<?php

namespace App\Filament\Resources;

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Filament\Resources\ConsultationResource\Pages;
use App\Models\Consultation;
use App\Policies\ConsultationPolicy;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manager inbox for persisted (logged-in) consultations.
 *
 * Authorization is delegated to {@see ConsultationPolicy}: Owner
 * and Direktur see every bidang (oversight); a Manager only its own; Finance,
 * HR, Mitra, Mandor and Konsumen never see the resource. The list query is
 * additionally narrowed to a Manager's bidang so per-record gating is never the
 * only line of defence.
 *
 * Guest (no-login) sessions are NOT handled here — they live only in Redis and
 * arrive in B3/B4 (ADR-0003).
 */
class ConsultationResource extends Resource
{
    protected static ?string $model = Consultation::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Konsultasi';

    protected static ?string $modelLabel = 'Konsultasi';

    protected static ?string $pluralModelLabel = 'Konsultasi';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('konsumen.name')
                    ->label('Konsumen')
                    ->default('—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bidang')
                    ->label('Bidang')
                    ->badge()
                    ->formatStateUsing(fn (?Bidang $state): ?string => $state?->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ConsultationStatus $state): string => $state->label())
                    ->color(fn (ConsultationStatus $state): string => match ($state) {
                        ConsultationStatus::Open => 'info',
                        ConsultationStatus::Deal => 'success',
                        ConsultationStatus::Closed => 'gray',
                    }),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label('Ditangani')
                    ->default('Belum diklaim')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Aktivitas terakhir')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ConsultationStatus::cases())
                        ->mapWithKeys(fn (ConsultationStatus $s): array => [$s->value => $s->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('bidang')
                    ->label('Bidang')
                    ->options(collect(Bidang::cases())
                        ->mapWithKeys(fn (Bidang $b): array => [$b->value => $b->label()])
                        ->all())
                    // Only meaningful for cross-bidang overseers (Owner/Direktur);
                    // a Manager already sees a single bidang.
                    ->visible(fn (): bool => ! (auth()->user()?->isManager() ?? false)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Buka'),
            ])
            ->bulkActions([]);
    }

    /**
     * Narrow the list to a Manager's own bidang. Owner and Direktur are left
     * unfiltered for oversight across every unit.
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

    public static function canCreate(): bool
    {
        // Threads are opened by consumers (and, later, by deal-promotion), not
        // from this staff inbox.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConsultations::route('/'),
            'view' => Pages\ViewConsultation::route('/{record}'),
        ];
    }
}
