<?php

namespace App\Filament\Resources;

use App\Enums\PayrollStatus;
use App\Enums\PayrollType;
use App\Filament\Resources\PayrollResource\Pages;
use App\Filament\Resources\PayrollResource\RelationManagers\PayslipsRelationManager;
use App\Models\Payroll;
use App\Services\PayrollService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Payroll runs for HR and Finance (Fase 6-4). Authorization is PayrollPolicy:
 * HR, Finance and overseers view; nobody else.
 *
 * Segregation of duties is enforced by the two actions' gates:
 * - "Generate" (build/refresh a period's draft payslips) → generatePayroll gate
 *   = HR + overseers. Finance cannot generate.
 * - "Bayar" (post the cash-book expense, lock the period) → PayrollPolicy::pay
 *   = Finance + overseers. HR cannot pay.
 *
 * Runs are never hand-created or edited: generation and payment both route
 * through PayrollService (idempotent, BigDecimal-exact, Auditable).
 */
class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Kepegawaian';

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?string $modelLabel = 'Payroll';

    protected static ?string $pluralModelLabel = 'Payroll';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false; // Runs are generated via the action, never hand-created.
    }

    public static function canEdit($record): bool
    {
        return false; // Immutable — mutated only by generate/pay through the service.
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period_start')->label('Mulai')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('period_end')->label('Selesai')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (PayrollType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('payslips_count')->label('Slip')->counts('payslips'),
                Tables\Columns\TextColumn::make('net_total')
                    ->label('Total Net')
                    ->money('IDR')
                    ->state(fn (Payroll $record): string => (string) $record->payslips()->sum('net')),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PayrollStatus $state): string => $state->label())
                    ->color(fn (PayrollStatus $state): string => match ($state) {
                        PayrollStatus::Draft => 'gray',
                        PayrollStatus::Approved => 'warning',
                        PayrollStatus::Paid => 'success',
                    }),
                Tables\Columns\TextColumn::make('paid_at')->label('Dibayar')->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PayrollStatus::cases())
                        ->mapWithKeys(fn (PayrollStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Slip'),
                // Bayar: Finance/overseers only (SoD). HR never sees this button.
                Tables\Actions\Action::make('bayar')
                    ->label('Bayar')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Membayar payroll akan mencatat pengeluaran kas gaji dan mengunci absensi periode ini. Tindakan ini tidak dapat dibatalkan.')
                    ->visible(fn (Payroll $record): bool => $record->status !== PayrollStatus::Paid
                        && auth()->user()->can('pay', $record))
                    ->action(function (Payroll $record): void {
                        app(PayrollService::class)->pay($record, auth()->user());
                        Notification::make()->title('Payroll dibayar — pengeluaran kas dicatat, absensi periode terkunci.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            PayslipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrolls::route('/'),
            'view' => Pages\ViewPayroll::route('/{record}'),
        ];
    }
}
