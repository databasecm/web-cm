<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Only non-final POs (draft/ordered) reach edit (PurchaseOrderPolicy::update).
 * After saving item changes, line subtotals and the PO total are recomputed
 * BigDecimal-exact.
 */
class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(PurchaseOrderService::class)->recalculate($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
