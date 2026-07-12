<?php

namespace App\Filament\Resources\FinancingResource\RelationManagers;

use App\Enums\FinancingDocumentStatus;
use App\Models\FinancingDocument;
use App\Services\FinancingDocumentService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The bank reviews the application's documents (Fase 4-4). Consumers upload
 * through the API (4-5); here the bank accepts/rejects each pending document via
 * FinancingDocumentService, gated by FinancingDocumentPolicy::review.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Dokumen';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (FinancingDocumentStatus $state): string => $state->label())
                    ->color(fn (FinancingDocumentStatus $state): string => match ($state) {
                        FinancingDocumentStatus::Pending => 'warning',
                        FinancingDocumentStatus::Accepted => 'success',
                        FinancingDocumentStatus::Rejected => 'danger',
                    }),
                Tables\Columns\TextColumn::make('uploader.name')->label('Diunggah oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Direview oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->label('Catatan')->placeholder('—')->limit(40),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('terima')
                    ->label('Terima')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FinancingDocument $record): bool => $record->status === FinancingDocumentStatus::Pending
                        && auth()->user()->can('review', $record))
                    ->action(function (FinancingDocument $record): void {
                        app(FinancingDocumentService::class)->accept($record, auth()->user());
                        Notification::make()->title('Dokumen diterima.')->success()->send();
                    }),

                Tables\Actions\Action::make('tolak')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (FinancingDocument $record): bool => $record->status === FinancingDocumentStatus::Pending
                        && auth()->user()->can('review', $record))
                    ->form([
                        Forms\Components\Textarea::make('note')->label('Alasan penolakan')->maxLength(500),
                    ])
                    ->action(function (FinancingDocument $record, array $data): void {
                        app(FinancingDocumentService::class)->reject($record, auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Dokumen ditolak.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
