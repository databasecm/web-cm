<?php

namespace App\Filament\Resources;

use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers\AttendancesRelationManager;
use App\Filament\Resources\EmployeeResource\RelationManagers\StatusLogsRelationManager;
use App\Models\Employee;
use App\Services\EmployeeService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Employee master for HR (Fase 6-4). Authorization is EmployeePolicy: HR and
 * overseers manage everyone; a Mandor manages its own bidang; a Manager views
 * its own bidang read-only. Finance has NO access (Finance pays, never manages
 * workers); Mitra/Konsumen nothing.
 *
 * Daily wage and position are NOT edited on the plain form — they change only
 * through the "Ubah Gaji" / "Ubah Jabatan" actions, which route via
 * EmployeeService so every change lands in employee_status_logs (the HR paper
 * trail). The list is bidang-scoped for Manager/Mandor so they never see other
 * units' workers.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Kepegawaian';

    protected static ?string $navigationLabel = 'Karyawan';

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Karyawan';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Karyawan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
                    Forms\Components\Select::make('bidang')
                        ->label('Bidang')
                        ->options(collect(Bidang::cases())
                            ->mapWithKeys(fn (Bidang $b): array => [$b->value => $b->label()])
                            ->all())
                        ->required()
                        ->native(false)
                        // A Mandor's employees are always in the Mandor's own bidang;
                        // the field is fixed for them, and immutable once created.
                        ->disabled(fn (string $operation): bool => $operation === 'edit' || (bool) auth()->user()?->isMandor())
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->default(fn (): ?string => auth()->user()?->isMandor() ? auth()->user()?->bidang?->value : null),
                    Forms\Components\Select::make('type')
                        ->label('Tipe')
                        ->options(collect(EmployeeType::cases())
                            ->mapWithKeys(fn (EmployeeType $t): array => [$t->value => $t->label()])
                            ->all())
                        ->default(EmployeeType::Harian->value)
                        ->required()
                        ->native(false),
                    Forms\Components\TextInput::make('position')
                        ->label('Jabatan')
                        ->maxLength(255)
                        // Position changes via "Ubah Jabatan" (logged) after creation.
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'Gunakan aksi "Ubah Jabatan" (tercatat di riwayat).' : null),
                    Forms\Components\TextInput::make('daily_wage')
                        ->label('Upah Harian')
                        ->numeric()
                        ->prefix('Rp')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        // Wage changes ONLY via "Ubah Gaji" (logged) — never the plain form.
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'Gunakan aksi "Ubah Gaji" (tercatat di riwayat).' : null),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(collect(EmployeeStatus::cases())
                            ->mapWithKeys(fn (EmployeeStatus $s): array => [$s->value => $s->label()])
                            ->all())
                        ->default(EmployeeStatus::Aktif->value)
                        ->required()
                        ->native(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('bidang')
                    ->label('Bidang')
                    ->badge()
                    ->formatStateUsing(fn (Bidang $state): string => $state->label()),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('position')->label('Jabatan')->placeholder('—'),
                Tables\Columns\TextColumn::make('daily_wage')->label('Upah Harian')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeStatus $state): string => $state->label())
                    ->color(fn (EmployeeStatus $state): string => $state === EmployeeStatus::Aktif ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('manager.name')->label('Mandor')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bidang')
                    ->label('Bidang')
                    ->options(collect(Bidang::cases())
                        ->mapWithKeys(fn (Bidang $b): array => [$b->value => $b->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(collect(EmployeeType::cases())
                        ->mapWithKeys(fn (EmployeeType $t): array => [$t->value => $t->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(EmployeeStatus::cases())
                        ->mapWithKeys(fn (EmployeeStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('ubahJabatan')
                    ->label('Ubah Jabatan')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('warning')
                    ->visible(fn (Employee $record): bool => auth()->user()->can('update', $record))
                    ->form([
                        Forms\Components\TextInput::make('position')
                            ->label('Jabatan baru')
                            ->required()
                            ->default(fn (Employee $record): ?string => $record->position),
                        Forms\Components\DatePicker::make('effective_date')
                            ->label('Berlaku sejak')->native(false)->default(now()),
                    ])
                    ->action(function (array $data, Employee $record): void {
                        app(EmployeeService::class)->changePosition($record, $data['position'], auth()->user(), $data['effective_date'] ?? null);
                        Notification::make()->title('Jabatan diperbarui & dicatat di riwayat.')->success()->send();
                    }),
                Tables\Actions\Action::make('ubahGaji')
                    ->label('Ubah Gaji')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (Employee $record): bool => auth()->user()->can('update', $record))
                    ->form([
                        Forms\Components\TextInput::make('daily_wage')
                            ->label('Upah harian baru')->numeric()->prefix('Rp')->required()
                            ->default(fn (Employee $record): string => (string) $record->daily_wage),
                        Forms\Components\DatePicker::make('effective_date')
                            ->label('Berlaku sejak')->native(false)->default(now()),
                    ])
                    ->action(function (array $data, Employee $record): void {
                        app(EmployeeService::class)->changeWage($record, $data['daily_wage'], auth()->user(), $data['effective_date'] ?? null);
                        Notification::make()->title('Upah harian diperbarui & dicatat di riwayat.')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Manager and Mandor see only their own bidang; HR and overseers see all.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();

        if ($actor !== null && $actor->isBidangScoped() && $actor->bidang !== null) {
            $query->where('bidang', $actor->bidang->value);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            AttendancesRelationManager::class,
            StatusLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
