<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\BastParty;
use App\Enums\BastStatus;
use App\Models\Bast;
use App\Models\Project;
use App\Services\BastPdf;
use App\Services\BastService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manager surface for a project's BAST (Fase 3-3). One BAST per project (1—1).
 *
 * The Manager issues the draft, attaches the document (path/link — binary upload
 * deferred to the media phase), and records the COMPANY signature on the firm's
 * behalf. The customer signs through the Sanctum API. When both sides have
 * signed, BastService flips the BAST to signed and opens the pelunasan (3-2).
 * Authorization is delegated to BastPolicy / the issueBast gate.
 */
class BastRelationManager extends RelationManager
{
    protected static string $relationship = 'bast';

    protected static ?string $title = 'BAST';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('status')
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (BastStatus $state): string => $state->label())
                    ->color(fn (BastStatus $state): string => $state === BastStatus::Signed ? 'success' : 'gray'),
                Tables\Columns\IconColumn::make('signed_customer')->label('TTD Konsumen')->boolean(),
                Tables\Columns\TextColumn::make('customerSigner.name')->label('Oleh (Konsumen)')->placeholder('—'),
                Tables\Columns\IconColumn::make('signed_company')->label('TTD Perusahaan')->boolean(),
                Tables\Columns\TextColumn::make('companySigner.name')->label('Oleh (Perusahaan)')->placeholder('—'),
                Tables\Columns\TextColumn::make('signed_at')->label('Ditandatangani')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('file')->label('Dokumen')->placeholder('—')->limit(40),
            ])
            ->headerActions([
                // Issue the draft BAST (only when none exists yet; the service
                // guards that the project is active and enforces the 1—1 rule).
                Tables\Actions\Action::make('terbitkanBast')
                    ->label('Terbitkan BAST')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => $this->getOwnerRecord()->bast === null
                        && auth()->user()->can('issueBast', $this->getOwnerRecord()))
                    ->form([
                        Forms\Components\TextInput::make('file')
                            ->label('Dokumen BAST (path/tautan)')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        /** @var Project $project */
                        $project = $this->getOwnerRecord();
                        app(BastService::class)->issue($project, $data['file'] ?? null);

                        Notification::make()->title('BAST diterbitkan.')->success()->send();
                    }),
            ])
            ->actions([
                // Attach/replace the document reference.
                Tables\Actions\Action::make('isiDokumen')
                    ->label('Isi Dokumen')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (Bast $record): bool => auth()->user()->can('signCompany', $record))
                    ->fillForm(fn (Bast $record): array => ['file' => $record->file])
                    ->form([
                        Forms\Components\TextInput::make('file')
                            ->label('Dokumen BAST (path/tautan)')
                            ->maxLength(255),
                    ])
                    ->action(function (Bast $record, array $data): void {
                        app(BastService::class)->setFile($record, $data['file'] ?? null);
                        Notification::make()->title('Dokumen diperbarui.')->success()->send();
                    }),

                // Download the signed BAST document PDF (Fase 3-7).
                Tables\Actions\Action::make('unduhBast')
                    ->label('Unduh BAST')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (Bast $record): bool => auth()->user()->can('downloadPdf', $record))
                    ->action(function (Bast $record) {
                        $pdf = app(BastPdf::class);

                        return response()->streamDownload(
                            fn () => print ($pdf->make($record)->output()),
                            $pdf->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),

                // Record the COMPANY signature on the firm's behalf.
                Tables\Actions\Action::make('ttdPerusahaan')
                    ->label('Tanda Tangan Perusahaan')
                    ->icon('heroicon-o-pencil-square')
                    ->requiresConfirmation()
                    ->visible(fn (Bast $record): bool => ! $record->signed_company
                        && auth()->user()->can('signCompany', $record))
                    ->action(function (Bast $record): void {
                        app(BastService::class)->recordSignature($record, BastParty::Company, auth()->id());
                        Notification::make()->title('Tanda tangan perusahaan direkam.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
