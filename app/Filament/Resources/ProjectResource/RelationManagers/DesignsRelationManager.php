<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DesignStatus;
use App\Models\Design;
use App\Models\Project;
use App\Services\DesignService;
use App\Services\MediaService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;

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
                Tables\Columns\IconColumn::make('file')->label('Berkas')
                    ->boolean()
                    ->state(fn (Design $record): bool => $record->file !== null),
                Tables\Columns\TextColumn::make('approver.name')->label('Disetujui oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('approved_at')->label('Disetujui')->dateTime('d M Y H:i')->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('tambahVersi')
                    ->label('Tambah Versi')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()->can('create', Design::class))
                    ->form([
                        Forms\Components\FileUpload::make('upload')
                            ->label('Berkas (gambar / PDF)')
                            ->disk(config('media.disk'))
                            ->storeFiles(false) // hand the temp file to MediaService (single validator)
                            ->acceptedFileTypes((new Design)->mediaDescriptor()->allowedMimes())
                            ->maxSize((new Design)->mediaDescriptor()->maxKb()),
                        Forms\Components\Textarea::make('notes')->label('Catatan')->maxLength(1000),
                    ])
                    ->action(function (array $data): void {
                        /** @var Project $project */
                        $project = $this->getOwnerRecord();

                        $upload = $data['upload'] ?? null;
                        $file = is_array($upload) ? reset($upload) : $upload;
                        $key = $file instanceof UploadedFile
                            ? app(MediaService::class)->store(new Design, $file)
                            : null;

                        app(DesignService::class)->addVersion($project, ['file' => $key, 'notes' => $data['notes'] ?? null]);
                        Notification::make()->title('Versi desain ditambahkan.')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('unduh')
                    ->label('Unduh')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Design $record): bool => $record->file !== null
                        && auth()->user()->can('view', $record))
                    ->url(fn (Design $record): string => app(MediaService::class)->temporaryUrl($record))
                    ->openUrlInNewTab(),
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
