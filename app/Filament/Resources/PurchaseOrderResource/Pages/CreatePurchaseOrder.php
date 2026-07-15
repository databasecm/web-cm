<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Attribute the PO to its creator (the orderer) and give it a temporary
     * unique number; the final PO-000123 number is stamped from the id in
     * afterCreate.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['ordered_by'] = auth()->id();
        $data['po_number'] ??= 'PO-'.Str::upper(Str::random(10));

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->update([
            'po_number' => 'PO-'.str_pad((string) $this->record->id, 6, '0', STR_PAD_LEFT),
        ]);

        // Compute line subtotals + PO total (BigDecimal) from the saved items.
        app(PurchaseOrderService::class)->recalculate($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
