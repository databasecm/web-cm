<?php

namespace App\Filament\Resources;

use App\Enums\MaterialSource;
use App\Filament\Resources\MaterialResource\Pages;
use App\Filament\Resources\MaterialResource\RelationManagers\PriceHistoryRelationManager;
use App\Models\Material;
use App\Models\Supplier;
use App\Services\MaterialPriceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Material database UI (Fase 2A-4). Authorization via MaterialPolicy: every
 * internal account may view; only Owner/Direktur/Manager manage; Mitra/Konsumen
 * have no access.
 *
 * Price is set once on create; afterwards it changes ONLY through the "Ubah
 * Harga" action, which routes via MaterialPriceService so the change is
 * attributed, journalled to price history, and triggers the AHSAP staleness
 * chain (Fase 2A-3). The plain edit form cannot touch price.
 */
class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Material';

    protected static ?string $modelLabel = 'Material';

    protected static ?string $pluralModelLabel = 'Material';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Material')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama Material')->required()->maxLength(255),
                    Forms\Components\TextInput::make('brand')->label('Merk')->maxLength(255),
                    Forms\Components\TextInput::make('unit')->label('Satuan')->maxLength(20)->placeholder('sak, m³, batang, …'),
                    Forms\Components\TextInput::make('price')
                        ->label('Harga')
                        ->numeric()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        // Price changes only via the "Ubah Harga" action (service).
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'Gunakan aksi "Ubah Harga" untuk mengubah harga (tercatat di riwayat).'
                            : null),
                    Forms\Components\Toggle::make('is_sni')->label('SNI'),
                    Forms\Components\Select::make('source')
                        ->label('Sumber')
                        ->options(collect(MaterialSource::cases())
                            ->mapWithKeys(fn (MaterialSource $s): array => [$s->value => $s->label()])
                            ->all())
                        ->default(MaterialSource::Internal->value)
                        ->required()
                        ->native(false),
                    Forms\Components\Textarea::make('spec')->label('Spesifikasi')->maxLength(1000)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Pemasok')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('supplier_id')
                        ->label('Supplier terdaftar')
                        ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                        ->searchable()
                        ->placeholder('— opsional —'),
                    Forms\Components\TextInput::make('supplier_name')->label('Toko/Supplier (teks bebas)')->maxLength(255),
                    Forms\Components\TextInput::make('supplier_address')->label('Alamat')->maxLength(255)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('brand')->label('Merk')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('unit')->label('Satuan'),
                Tables\Columns\TextColumn::make('price')->label('Harga')->money('IDR')->sortable(),
                Tables\Columns\IconColumn::make('is_sni')->label('SNI')->boolean(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber')
                    ->badge()
                    ->formatStateUsing(fn (MaterialSource $state): string => $state->label()),
                Tables\Columns\TextColumn::make('supplier.company_name')->label('Supplier')->default('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_sni')->label('SNI'),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Sumber')
                    ->options(collect(MaterialSource::cases())
                        ->mapWithKeys(fn (MaterialSource $s): array => [$s->value => $s->label()])
                        ->all()),
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'company_name')
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('ubahHarga')
                    ->label('Ubah Harga')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (Material $record): bool => auth()->user()->can('update', $record))
                    ->form([
                        Forms\Components\TextInput::make('price')
                            ->label('Harga baru')
                            ->numeric()
                            ->required()
                            ->default(fn (Material $record): string => (string) $record->price),
                    ])
                    ->action(function (array $data, Material $record): void {
                        app(MaterialPriceService::class)->change($record, $data['price'], auth()->user());
                        Notification::make()->title('Harga material diperbarui & dicatat di riwayat.')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            PriceHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterials::route('/'),
            'create' => Pages\CreateMaterial::route('/create'),
            'edit' => Pages\EditMaterial::route('/{record}/edit'),
        ];
    }
}
