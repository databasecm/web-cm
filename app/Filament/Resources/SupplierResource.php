<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Minimal supplier directory (Fase 2A-4). Authorization via SupplierPolicy:
 * every internal account may view; only Owner/Direktur/Manager manage;
 * Mitra/Konsumen have no access. The supplier self-service portal arrives later.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Supplier';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('company_name')
                ->label('Nama Perusahaan')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('phone')
                ->label('Telepon')
                ->tel()
                ->maxLength(30),
            Forms\Components\Textarea::make('address')
                ->label('Alamat')
                ->maxLength(500)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label('Nama Perusahaan')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->label('Telepon')->toggleable(),
                Tables\Columns\TextColumn::make('materials_count')
                    ->label('Jml Material')
                    ->counts('materials'),
                Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y')->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
