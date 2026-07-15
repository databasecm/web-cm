<?php

namespace App\Filament\Resources;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The Finance cash book (buku kas, Fase 6-3) — every money movement as one row.
 *
 * Authorization is TransactionPolicy: only Finance and Owner/Direktur reach this
 * resource at all. Auto-sourced rows (installments, financing disbursements,
 * payroll) are READ-ONLY here — they mirror real events and their edit/delete
 * gates are closed by the policy (isManual === false). Only hand-entered rows
 * are editable, and creation routes through TransactionService so an
 * auto-sourced category can never be posted by hand (anti-double-count).
 *
 * The classification (type + category) of a manual row is fixed once created;
 * to reclassify, delete and re-add. Totals and saldo live on the Finance
 * dashboard.
 */
class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Buku Kas';

    protected static ?string $modelLabel = 'Transaksi';

    protected static ?string $pluralModelLabel = 'Buku Kas';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Entri Kas')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipe')
                        ->options([
                            TransactionType::Income->value => TransactionType::Income->label(),
                            TransactionType::Expense->value => TransactionType::Expense->label(),
                        ])
                        ->default(TransactionType::Expense->value)
                        ->required()
                        ->native(false)
                        ->live()
                        // Classification is fixed after creation (reclassify = delete + re-add).
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        // Clear a now-invalid category when the direction changes.
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('category', null)),

                    Forms\Components\Select::make('category')
                        ->label('Kategori')
                        ->options(fn (Forms\Get $get): array => static::categoryOptions($get('type')))
                        ->required()
                        ->native(false)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Gaji, material & pembayaran konsumen tercatat otomatis — tak bisa diinput manual.'),

                    Forms\Components\TextInput::make('amount')
                        ->label('Nominal')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('Rp'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Tanggal')
                        ->required()
                        ->default(now())
                        ->native(false),

                    Forms\Components\Textarea::make('description')
                        ->label('Keterangan')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Tanggal')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (TransactionType $state): string => $state->label())
                    ->color(fn (TransactionType $state): string => $state === TransactionType::Income ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (TransactionCategory $state): string => $state->label()),
                Tables\Columns\TextColumn::make('description')->label('Keterangan')->limit(40)->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total (terfilter)')->money('IDR')),
                Tables\Columns\TextColumn::make('recorder.name')->label('Pencatat')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber')
                    ->badge()
                    ->state(fn (Transaction $record): string => $record->isManual() ? 'Manual' : 'Otomatis')
                    ->color(fn (Transaction $record): string => $record->isManual() ? 'gray' : 'info'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options([
                        TransactionType::Income->value => TransactionType::Income->label(),
                        TransactionType::Expense->value => TransactionType::Expense->label(),
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(collect(TransactionCategory::cases())
                        ->mapWithKeys(fn (TransactionCategory $c): array => [$c->value => $c->label()])
                        ->all()),
                Tables\Filters\Filter::make('date')
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
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Manual-entriable categories for the chosen direction (empty until a type is
     * picked). Keyed value => label for a Select.
     *
     * @return array<string, string>
     */
    protected static function categoryOptions(?string $type): array
    {
        $direction = TransactionType::tryFrom((string) $type);

        if ($direction === null) {
            return [];
        }

        return collect(TransactionCategory::manualOptions($direction))
            ->mapWithKeys(fn (TransactionCategory $c): array => [$c->value => $c->label()])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}
