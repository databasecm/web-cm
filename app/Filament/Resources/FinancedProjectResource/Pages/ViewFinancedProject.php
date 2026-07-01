<?php

namespace App\Filament\Resources\FinancedProjectResource\Pages;

use App\Enums\BastStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\FinancedProjectResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only project view for the financing Mitra. No header actions and no
 * editable fields — the bank only observes status, progress, the contract value
 * and the installment schedule (rendered by InstallmentsRelationManager).
 */
class ViewFinancedProject extends ViewRecord
{
    protected static string $resource = FinancedProjectResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Proyek')
                ->columns(3)
                ->schema([
                    TextEntry::make('title')->label('Judul'),
                    TextEntry::make('bidang')->label('Bidang')->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (ProjectStatus $state): string => $state->label()),
                    TextEntry::make('contract_value')->label('Nilai Kontrak')->money('IDR')->placeholder('—'),
                    TextEntry::make('progress_percent')->label('Progres')->suffix(' %'),
                ]),
            Section::make('Konsumen')
                ->columns(3)
                ->schema([
                    TextEntry::make('konsumen.name')->label('Nama')->placeholder('—'),
                    TextEntry::make('konsumen.email')->label('Email')->placeholder('—'),
                    TextEntry::make('konsumen.phone')->label('Telepon')->placeholder('—'),
                ]),
            // Serah terima (BAST) — read-only for the bank: it observes the
            // handover status only and never signs (CLAUDE.md §6.5).
            Section::make('Serah Terima (BAST)')
                ->columns(2)
                ->schema([
                    TextEntry::make('bast.status')
                        ->label('Status')
                        ->badge()
                        ->placeholder('Belum diterbitkan')
                        ->formatStateUsing(fn (?BastStatus $state): ?string => $state?->label())
                        ->color(fn (?BastStatus $state): string => $state === BastStatus::Signed ? 'success' : 'gray'),
                    TextEntry::make('bast.signed_at')->label('Ditandatangani')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
        ]);
    }
}
