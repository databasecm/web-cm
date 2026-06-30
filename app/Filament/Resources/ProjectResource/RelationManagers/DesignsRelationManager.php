<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DesignStatus;
use App\Models\Design;
use App\Models\Project;
use App\Services\DesignService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manager surface for a project's design versions (Fase 2B-2): add a new version
 * (auto-numbered draft) and submit it to the consumer. Approval is the
 * consumer's, via the Sanctum API (2B-7) — not offered here.
 *
 * Versions are numbered by DesignService; the "file" reference is a path/link
 * for now (binary upload + object storage arrives with the media phase).
 */
class DesignsRelationManager extends RelationManager
{
    protected static string $relationship = 'designs';

    protected static ?string $title = 'Desain';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')->label('Versi')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (DesignStatus $state): string => $state->label())
                    ->color(fn (DesignStatus $state): string => match ($state) {
                        DesignStatus::Draft => 'gray',
                        DesignStatus::Submitted => 'warning',
                        DesignStatus::Approved => 'success',
                    }),
                Tables\Columns\TextColumn::make('file')->label('Berkas')->default('—')->limit(40),
                Tables\Columns\TextColumn::make('approver.name')->label('Disetujui oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('approved_at')->label('Disetujui')->dateTime('d M Y H:i')->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('tambahVersi')
                    ->label('Tambah Versi')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()->can('create', Design::class))
                    ->form([
                        Forms\Components\TextInput::make('file')->label('Berkas (path/link)')->maxLength(2048),
                        Forms\Components\Textarea::make('notes')->label('Catatan')->maxLength(1000),
                    ])
                    ->action(function (array $data): void {
                        /** @var Project $project */
                        $project = $this->getOwnerRecord();
                        app(DesignService::class)->addVersion($project, $data);
                        Notification::make()->title('Versi desain ditambahkan.')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('ajukan')
                    ->label('Ajukan')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (Design $record): bool => $record->status === DesignStatus::Draft
                        && auth()->user()->can('submit', $record))
                    ->action(function (Design $record): void {
                        app(DesignService::class)->submit($record);
                        Notification::make()->title('Desain diajukan ke konsumen.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
