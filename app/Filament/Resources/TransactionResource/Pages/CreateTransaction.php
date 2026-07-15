<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource;
use App\Services\TransactionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * Route creation through the service so the manual-category guard (and the
     * `manual` tag + recorder attribution) is applied — the form can't post an
     * auto-sourced category, and neither can a crafted request.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(TransactionService::class)->recordManual(
            TransactionType::from($data['type']),
            TransactionCategory::from($data['category']),
            (string) $data['amount'],
            (string) $data['date'],
            $data['description'] ?? null,
            auth()->user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
