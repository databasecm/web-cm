<?php

namespace App\Filament\Resources\FinancedProjectResource\RelationManagers;

use App\Enums\ReportMediaType;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view of a financed project's daily field reports for the bank (Fase
 * 5-5). The financing Mitra observes progress narrative and media only — never
 * writes, comments on, or edits a report (§6.5). Worker attendance/wages are HR
 * data and are deliberately NOT surfaced here.
 */
class DailyReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'dailyReports';

    protected static ?string $title = 'Laporan Harian';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Tanggal')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('description')->label('Deskripsi')->limit(60),
                Tables\Columns\TextColumn::make('progress_note')->label('Catatan Progres')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('media_count')->label('Media')
                    ->getStateUsing(fn ($record): int => $record->media()->count()),
            ])
            ->headerActions([])
            ->actions([
                // Read-only detail (report + its media). No edit/delete.
                Tables\Actions\ViewAction::make()->label('Lihat')->infolist(fn (Infolist $infolist): Infolist => $infolist->schema([
                    Section::make('Laporan')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('date')->label('Tanggal')->date('d/m/Y'),
                            TextEntry::make('mandor.name')->label('Mandor')->placeholder('—'),
                            TextEntry::make('description')->label('Deskripsi')->columnSpanFull(),
                            TextEntry::make('progress_note')->label('Catatan Progres')->placeholder('—')->columnSpanFull(),
                        ]),
                    Section::make('Media')
                        ->schema([
                            RepeatableEntry::make('media')
                                ->label('')
                                ->schema([
                                    TextEntry::make('type')->label('Tipe')
                                        ->formatStateUsing(fn (ReportMediaType $state): string => $state->label()),
                                    TextEntry::make('caption')->label('Keterangan')->placeholder('—'),
                                    TextEntry::make('file')->label('Berkas')->placeholder('—'),
                                ])
                                ->columns(3),
                        ]),
                ])),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
