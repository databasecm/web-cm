<?php

namespace App\Filament\Resources;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Resources\PurchaseOrderResource\RelationManagers\ItemsRelationManager;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Material purchase orders (Fase 6-5). Authorization is PurchaseOrderPolicy (SoD):
 * Finance/O-D run the full lifecycle; a Manager drafts + orders POs for its own
 * bidang but NEVER receives (receiving posts the material expense — Finance's
 * cash-out). Received/cancelled POs are final and read-only.
 *
 * The expense is posted only on "Terima" (receive) via PurchaseOrderService,
 * tagged to the project (per-project P&L). Line unit_price is snapshotted from
 * the material at PO creation; totals are recomputed BigDecimal-exact on save.
 */
class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'PO Material';

    protected static ?string $modelLabel = 'PO Material';

    protected static ?string $pluralModelLabel = 'PO Material';

    protected static ?int $navigationSort = 35;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Purchase Order')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Proyek')
                        ->options(fn (): array => static::projectOptions())
                        ->searchable()
                        ->placeholder('— tanpa proyek —')
                        ->helperText('Tertaut ke laba-rugi proyek saat PO diterima.'),
                    Forms\Components\Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                        ->searchable()
                        ->placeholder('— opsional —'),
                    Forms\Components\Textarea::make('note')->label('Catatan')->maxLength(1000)->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Item')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('Tambah item')
                        ->columns(12)
                        ->defaultItems(1)
                        ->schema([
                            Forms\Components\Select::make('material_id')
                                ->label('Material')
                                ->options(fn (): array => Material::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->live()
                                ->placeholder('— bebas —')
                                // Snapshot the material's current price/unit/name on pick;
                                // unit_price stays editable but is frozen once saved.
                                ->afterStateUpdated(function ($state, Forms\Set $set): void {
                                    $material = $state !== null ? Material::find($state) : null;
                                    if ($material !== null) {
                                        $set('description', $material->name);
                                        $set('unit', $material->unit);
                                        $set('unit_price', (string) $material->price);
                                    }
                                })
                                ->columnSpan(3),
                            Forms\Components\TextInput::make('description')->label('Deskripsi')->required()->columnSpan(3),
                            Forms\Components\TextInput::make('unit')->label('Satuan')->columnSpan(2),
                            Forms\Components\TextInput::make('quantity')->label('Qty')->numeric()->required()->minValue(0)->columnSpan(2),
                            Forms\Components\TextInput::make('unit_price')->label('Harga')->numeric()->required()->minValue(0)->prefix('Rp')->columnSpan(2),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('po_number')->label('No. PO')->searchable(),
                Tables\Columns\TextColumn::make('project.title')->label('Proyek')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('supplier.company_name')->label('Supplier')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('items_count')->label('Item')->counts('items'),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus $state): string => $state->label())
                    ->color(fn (PurchaseOrderStatus $state): string => match ($state) {
                        PurchaseOrderStatus::Draft => 'gray',
                        PurchaseOrderStatus::Ordered => 'warning',
                        PurchaseOrderStatus::Received => 'success',
                        PurchaseOrderStatus::Cancelled => 'danger',
                    }),
                Tables\Columns\TextColumn::make('received_at')->label('Diterima')->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PurchaseOrderStatus::cases())
                        ->mapWithKeys(fn (PurchaseOrderStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('pesan')
                    ->label('Pesan')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record): bool => auth()->user()->can('order', $record))
                    ->action(function (PurchaseOrder $record): void {
                        app(PurchaseOrderService::class)->order($record, auth()->user());
                        Notification::make()->title('PO dipesan ke supplier.')->success()->send();
                    }),
                // Terima: Finance/overseers only (SoD) — this posts the material expense.
                Tables\Actions\Action::make('terima')
                    ->label('Terima')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Menerima PO akan mencatat pengeluaran kas material sebesar total PO dan mengunci PO (final).')
                    ->visible(fn (PurchaseOrder $record): bool => auth()->user()->can('receive', $record))
                    ->action(function (PurchaseOrder $record): void {
                        app(PurchaseOrderService::class)->receive($record, auth()->user());
                        Notification::make()->title('PO diterima — pengeluaran material dicatat ke buku kas.')->success()->send();
                    }),
                Tables\Actions\Action::make('batal')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record): bool => auth()->user()->can('cancel', $record))
                    ->action(function (PurchaseOrder $record): void {
                        app(PurchaseOrderService::class)->cancel($record, auth()->user());
                        Notification::make()->title('PO dibatalkan.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * Projects a Manager may attach a PO to are limited to its own bidang; Finance
     * and overseers see all.
     *
     * @return array<int, string>
     */
    protected static function projectOptions(): array
    {
        $query = Project::query()->orderBy('title');
        $actor = auth()->user();

        if ($actor !== null && $actor->isManager() && $actor->bidang !== null) {
            $query->where('bidang', $actor->bidang->value);
        }

        return $query->pluck('title', 'id')->all();
    }

    /** Managers see only their own bidang's POs. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();

        if ($actor !== null && $actor->isManager() && $actor->bidang !== null) {
            $query->whereHas('project', fn (Builder $q) => $q->where('bidang', $actor->bidang->value));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
