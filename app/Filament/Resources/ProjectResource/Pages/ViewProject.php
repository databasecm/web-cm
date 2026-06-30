<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProgressService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
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
                    TextEntry::make('progress_percent')->label('Progres')->suffix(' %'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Setting progress is a project mutation: managing staff only
            // (Manager in its bidang). Opens progress-due installments at ≥50%.
            Actions\Action::make('aturProgres')
                ->label('Atur Progres')
                ->icon('heroicon-o-chart-bar')
                ->visible(fn (Project $record): bool => auth()->user()->can('update', $record))
                ->form([
                    Forms\Components\TextInput::make('progress_percent')
                        ->label('Progres (%)')
                        ->numeric()->required()->minValue(0)->maxValue(100),
                ])
                ->action(function (array $data, Project $record): void {
                    app(ProgressService::class)->setProgress($record, $data['progress_percent'], auth()->id());
                    Notification::make()->title('Progres diperbarui.')->success()->send();
                }),
        ];
    }
}
