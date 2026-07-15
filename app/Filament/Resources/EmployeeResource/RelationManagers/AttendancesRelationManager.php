<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only attendance recap for a worker (Fase 6-4) — HR's pre-payroll view of
 * hadir/izin/alpa days over a range. Attendance is the Mandor's to write (via
 * the field app) and locks once the period's payroll is paid (ADR-0016), so it
 * is never edited here.
 */
class AttendancesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendances';

    protected static ?string $title = 'Rekap Absensi';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Tanggal')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AttendanceStatus $state): string => $state->label())
                    ->color(fn (AttendanceStatus $state): string => match ($state) {
                        AttendanceStatus::Hadir => 'success',
                        AttendanceStatus::Izin => 'warning',
                        AttendanceStatus::Alpa => 'danger',
                    }),
                Tables\Columns\TextColumn::make('project.title')->label('Proyek')->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->label('Catatan')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(AttendanceStatus::cases())
                        ->mapWithKeys(fn (AttendanceStatus $s): array => [$s->value => $s->label()])
                        ->all()),
                Tables\Filters\Filter::make('range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari tanggal')->native(false),
                        Forms\Components\DatePicker::make('until')->label('Sampai tanggal')->native(false),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date));
                    }),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
