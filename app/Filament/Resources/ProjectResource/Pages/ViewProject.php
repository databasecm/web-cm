<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Proyek')
                ->columns(3)
                ->schema([
                    TextEntry::make('title')->label('Judul'),
                    TextEntry::make('konsumen.name')->label('Konsumen'),
                    TextEntry::make('bidang')->label('Bidang')->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (ProjectStatus $state): string => $state->label()),
                    TextEntry::make('manager.name')->label('Manager')->placeholder('—'),
                    TextEntry::make('contract_value')->label('Nilai Kontrak')->money('IDR')->placeholder('—'),
                ]),
        ]);
    }
}
