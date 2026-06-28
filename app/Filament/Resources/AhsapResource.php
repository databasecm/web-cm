<?php

namespace App\Filament\Resources;

use App\Enums\AhsapComponentType;
use App\Enums\Bidang;
use App\Filament\Resources\AhsapResource\Pages;
use App\Models\Ahsap;
use App\Models\Material;
use App\Policies\AhsapPolicy;
use App\Services\AhsapReviewService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master AHSAP builder (Fase 2A-5). Authorization is delegated to
 * {@see AhsapPolicy}: every internal account may view, but only
 * Owner/Direktur/Manager manage, and a Manager is confined to its own bidang.
 * The list is additionally narrowed to a Manager's bidang.
 *
 * base_price is the authoritative sum computed by AhsapCalculator on save
 * (ADR-0004); the form shows a live preview as components change. A material
 * component's unit_price is a snapshot of Material.price.
 */
class AhsapResource extends Resource
{
    protected static ?string $model = Ahsap::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'AHSAP';

    protected static ?string $modelLabel = 'AHSAP';

    protected static ?string $pluralModelLabel = 'AHSAP';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Analisa Harga Satuan Pekerjaan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Kode')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Pekerjaan')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('bidang')
                        ->label('Bidang')
                        ->options(fn (): array => static::bidangOptions())
                        ->required()
                        // A Manager is locked to its own unit; overseers choose.
                        ->default(fn (): ?string => auth()->user()?->isManager()
                            ? auth()->user()->bidang?->value
                            : null)
                        ->disabled(fn (): bool => (bool) auth()->user()?->isManager())
                        ->dehydrated()
                        ->native(false),
                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('m², m³, titik, …'),
                ]),

            Forms\Components\Section::make('Komponen')
                ->schema([
                    Forms\Components\Repeater::make('components')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('Tambah komponen')
                        ->columns(12)
                        ->live()
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->label('Tipe')
                                ->options(collect(AhsapComponentType::cases())
                                    ->mapWithKeys(fn (AhsapComponentType $t): array => [$t->value => $t->label()])
                                    ->all())
                                ->default(AhsapComponentType::Material->value)
                                ->required()
                                ->live()
                                ->native(false)
                                ->columnSpan(3),

                            // Material component → pick from the Material database;
                            // unit_price is snapshotted from the chosen material.
                            Forms\Components\Select::make('material_id')
                                ->label('Material')
                                ->options(fn (): array => Material::query()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (Material $m): array => [
                                        $m->id => trim("{$m->name} ".($m->brand ? "({$m->brand})" : '')),
                                    ])
                                    ->all())
                                ->searchable()
                                ->visible(fn (Get $get): bool => $get('type') === AhsapComponentType::Material->value)
                                ->required(fn (Get $get): bool => $get('type') === AhsapComponentType::Material->value)
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set): void {
                                    $material = Material::find($state);
                                    if ($material !== null) {
                                        $set('unit_price', (string) $material->price);
                                    }
                                })
                                ->columnSpan(4),

                            // Upah/alat → free-text description.
                            Forms\Components\TextInput::make('description')
                                ->label('Uraian')
                                ->visible(fn (Get $get): bool => $get('type') !== AhsapComponentType::Material->value)
                                ->maxLength(255)
                                ->columnSpan(4),

                            Forms\Components\TextInput::make('coefficient')
                                ->label('Koefisien')
                                ->numeric()
                                ->required()
                                ->default(1)
                                ->live(onBlur: true)
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('unit_price')
                                ->label('Harga Satuan')
                                ->numeric()
                                ->required()
                                ->default(0)
                                ->live(onBlur: true)
                                // Snapshot from the material — read-only for material rows.
                                ->readOnly(fn (Get $get): bool => $get('type') === AhsapComponentType::Material->value)
                                ->columnSpan(3),
                        ]),

                    Forms\Components\Placeholder::make('base_price_preview')
                        ->label('Perkiraan Base Price')
                        ->content(fn (Get $get): string => 'Rp '.static::previewBasePrice($get('components') ?? [])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('bidang')
                    ->label('Bidang')
                    ->badge()
                    ->formatStateUsing(fn (?Bidang $state): ?string => $state?->label()),
                Tables\Columns\TextColumn::make('unit')->label('Satuan'),
                Tables\Columns\TextColumn::make('base_price')
                    ->label('Base Price')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\IconColumn::make('needs_review')
                    ->label('Perlu Tinjau')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bidang')
                    ->label('Bidang')
                    ->options(collect(Bidang::cases())
                        ->mapWithKeys(fn (Bidang $b): array => [$b->value => $b->label()])
                        ->all())
                    ->visible(fn (): bool => ! (auth()->user()?->isManager() ?? false)),
                Tables\Filters\TernaryFilter::make('needs_review')->label('Perlu Tinjau'),
            ])
            ->actions([
                Tables\Actions\Action::make('resync')
                    ->label('Sinkronkan & Hitung Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Ahsap $record): bool => $record->needs_review
                        && auth()->user()->can('update', $record))
                    ->action(function (Ahsap $record): void {
                        app(AhsapReviewService::class)->resync($record, auth()->user());
                        Notification::make()->title('AHSAP disinkronkan & dihitung ulang.')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Narrow the list to a Manager's own bidang; overseers and view-only
     * internal staff see every bidang (shared master, AhsapPolicy::view).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();

        if ($actor?->isManager() && $actor->bidang !== null) {
            $query->where('bidang', $actor->bidang->value);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAhsap::route('/'),
            'create' => Pages\CreateAhsap::route('/create'),
            'edit' => Pages\EditAhsap::route('/{record}/edit'),
        ];
    }

    /**
     * Bidang options: a Manager only its own unit; everyone else all units.
     *
     * @return array<string, string>
     */
    protected static function bidangOptions(): array
    {
        $actor = auth()->user();

        if ($actor?->isManager() && $actor->bidang !== null) {
            return [$actor->bidang->value => $actor->bidang->label()];
        }

        return collect(Bidang::cases())
            ->mapWithKeys(fn (Bidang $b): array => [$b->value => $b->label()])
            ->all();
    }

    /**
     * Live, display-only base_price preview from the repeater state. The
     * authoritative value is recomputed by AhsapCalculator (BigDecimal) on save.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    protected static function previewBasePrice(array $components): string
    {
        $total = BigDecimal::zero();

        foreach ($components as $row) {
            $coefficient = $row['coefficient'] ?? null;
            $unitPrice = $row['unit_price'] ?? null;

            if (! is_numeric($coefficient) || ! is_numeric($unitPrice)) {
                continue;
            }

            $total = $total->plus(BigDecimal::of((string) $coefficient)->multipliedBy((string) $unitPrice));
        }

        return number_format((float) (string) $total->toScale(2, RoundingMode::HALF_UP), 2, ',', '.');
    }
}
