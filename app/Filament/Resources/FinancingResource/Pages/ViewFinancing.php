<?php

namespace App\Filament\Resources\FinancingResource\Pages;

use App\Enums\FinancingStatus;
use App\Filament\Resources\FinancingResource;
use App\Models\Financing;
use App\Services\FinancingDocumentService;
use App\Services\FinancingService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The bank's application detail + lifecycle actions (Fase 4-4). Every action is
 * a thin call into FinancingService / FinancingDocumentService and is gated by
 * FinancingPolicy::manageLifecycle. Project data is read-only context — there is
 * no action here that writes a project (§6.5).
 */
class ViewFinancing extends ViewRecord
{
    protected static string $resource = FinancingResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Pengajuan')
                ->columns(3)
                ->schema([
                    TextEntry::make('project.title')->label('Proyek'),
                    TextEntry::make('konsumen.name')->label('Konsumen'),
                    TextEntry::make('amount')->label('Nilai')->money('IDR'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (FinancingStatus $state): string => $state->label()),
                    // Read-only project context — the bank never edits this.
                    TextEntry::make('project.bidang')->label('Bidang')->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('project.contract_value')->label('Nilai Kontrak')->money('IDR')->placeholder('—'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->transitionAction('mintaDokumen', 'Minta Dokumen', FinancingStatus::DocsRequired, 'heroicon-o-document-magnifying-glass', true),
            $this->transitionAction('jadwalkanInterview', 'Jadwalkan Wawancara', FinancingStatus::Interview, 'heroicon-o-calendar-days'),
            $this->transitionAction('setujui', 'Setujui', FinancingStatus::Approved, 'heroicon-o-check-circle'),
            $this->transitionAction('tolak', 'Tolak', FinancingStatus::Rejected, 'heroicon-o-x-circle', true),

            Actions\Action::make('cairkan')
                ->label('Cairkan Dana')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Financing $record): bool => $record->status === FinancingStatus::Approved
                    && auth()->user()->can('manageLifecycle', $record))
                ->action(function (Financing $record): void {
                    app(FinancingService::class)->disburse($record, auth()->user());
                    Notification::make()->title('Pembiayaan dicairkan; pemasukan tercatat.')->success()->send();
                }),
        ];
    }

    /**
     * A guarded lifecycle transition action. "Request more documents" routes
     * through FinancingDocumentService::requestMore (which reuses the transition);
     * the rest go straight through FinancingService::transition.
     */
    protected function transitionAction(string $name, string $label, FinancingStatus $target, string $icon, bool $withNote = false): Actions\Action
    {
        $action = Actions\Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (Financing $record): bool => $record->status->canTransitionTo($target)
                && auth()->user()->can('manageLifecycle', $record))
            ->action(function (Financing $record, array $data) use ($target): void {
                $note = $data['note'] ?? null;

                if ($target === FinancingStatus::DocsRequired) {
                    app(FinancingDocumentService::class)->requestMore($record, auth()->user(), $note);
                } else {
                    app(FinancingService::class)->transition($record, $target, auth()->user(), $note);
                }

                Notification::make()->title('Status pengajuan diperbarui.')->success()->send();
            });

        if ($withNote) {
            $action->form([
                Forms\Components\Textarea::make('note')->label('Catatan')->maxLength(500),
            ]);
        }

        return $action;
    }
}
