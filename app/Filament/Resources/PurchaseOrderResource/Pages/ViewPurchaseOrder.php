<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Purchase Order')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('po_number')->label('No. PO'),
                    Infolists\Components\TextEntry::make('project.title')->label('Proyek')->placeholder('—'),
                    Infolists\Components\TextEntry::make('supplier.company_name')->label('Supplier')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (PurchaseOrderStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('total')->label('Total')->money('IDR'),
                    Infolists\Components\TextEntry::make('received_at')->label('Diterima')->dateTime('d/m/Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('orderer.name')->label('Dibuat oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('receiver.name')->label('Diterima oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('note')->label('Catatan')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
