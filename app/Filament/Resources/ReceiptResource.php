<?php

namespace App\Filament\Resources;

use App\Enums\InstallmentStatus;
use App\Filament\Resources\ReceiptResource\Pages;
use App\Models\Installment;
use App\Models\Role;
use App\Services\PaymentReceiptPdf;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only cash surface listing paid installments so Finance / Owner / Direktur
 * can download their receipts (kuitansi) in the panel (Fase 3-7 follow-up).
 *
 * Deliberately a SEPARATE resource on Installment — not part of ProjectResource —
 * so Finance never gains visibility into project management (the RBAC invariant
 * "Finance does not see ProjectResource" stays intact). The query is confined to
 * paid terms and every mutation gate is closed; the download itself is gated by
 * InstallmentPolicy::downloadReceipt.
 */
class ReceiptResource extends Resource
{
    protected static ?string $model = Installment::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Kuitansi';

    protected static ?string $modelLabel = 'Kuitansi';

    protected static ?string $pluralModelLabel = 'Kuitansi';

    protected static ?int $navigationSort = 40;

    /** Finance keeps the cash book; overseers see everything. Nobody else. */
    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor !== null
            && ($actor->isFinance() || in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Only paid terms have a receipt. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', InstallmentStatus::Paid->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('project.title')->label('Proyek')->searchable(),
                Tables\Columns\TextColumn::make('project.konsumen.name')->label('Konsumen')->searchable(),
                Tables\Columns\TextColumn::make('label')->label('Termin'),
                Tables\Columns\TextColumn::make('percentage')->label('%')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim((string) $state, '0'), '.').'%'),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR'),
                Tables\Columns\TextColumn::make('paid_at')->label('Tanggal Bayar')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('unduhKuitansi')
                    ->label('Unduh Kuitansi')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Installment $record): bool => auth()->user()->can('downloadReceipt', $record))
                    ->action(function (Installment $record) {
                        $pdf = app(PaymentReceiptPdf::class);

                        return response()->streamDownload(
                            fn () => print ($pdf->make($record)->output()),
                            $pdf->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReceipts::route('/'),
        ];
    }
}
