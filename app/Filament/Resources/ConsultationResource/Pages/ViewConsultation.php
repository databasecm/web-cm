<?php

namespace App\Filament\Resources\ConsultationResource\Pages;

use App\Enums\ConsultationStatus;
use App\Enums\SenderType;
use App\Filament\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Policies\ConsultationPolicy;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Thread view for a single consultation: the conversation, a reply box, and the
 * status transitions (open → deal → closed). Every mutating action is gated by
 * {@see ConsultationPolicy}; a closed thread is read-only.
 */
class ViewConsultation extends ViewRecord
{
    protected static string $resource = ConsultationResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Konsultasi')
                ->columns(3)
                ->schema([
                    TextEntry::make('konsumen.name')->label('Konsumen')->default('—'),
                    TextEntry::make('bidang')
                        ->label('Bidang')
                        ->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (ConsultationStatus $state): string => $state->label())
                        ->color(fn (ConsultationStatus $state): string => match ($state) {
                            ConsultationStatus::Open => 'info',
                            ConsultationStatus::Deal => 'success',
                            ConsultationStatus::Closed => 'gray',
                        }),
                    TextEntry::make('manager.name')->label('Ditangani')->default('Belum diklaim'),
                ]),

            Section::make('Percakapan')
                ->schema([
                    RepeatableEntry::make('messages')
                        ->label('')
                        ->schema([
                            TextEntry::make('sender_type')
                                ->label('')
                                ->badge()
                                ->formatStateUsing(fn (SenderType $state): string => $state->label())
                                ->color(fn (SenderType $state): string => $state === SenderType::Manager ? 'primary' : 'gray'),
                            TextEntry::make('message')->label('')->columnSpan(2),
                            TextEntry::make('created_at')->label('')->since(),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction(),
            $this->markDealAction(),
            $this->closeAction(),
        ];
    }

    /**
     * Append a staff reply. If the thread is still unclaimed and the responder
     * is a Manager, claim it (set manager_id) per the claim model (ADR-0003).
     */
    protected function replyAction(): Actions\Action
    {
        return Actions\Action::make('balas')
            ->label('Balas')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Consultation $record): bool => auth()->user()->can('respond', $record))
            ->form([
                Forms\Components\Textarea::make('message')
                    ->label('Pesan')
                    ->required()
                    ->rows(3)
                    ->maxLength(5000),
            ])
            ->action(function (array $data, Consultation $record): void {
                abort_unless(auth()->user()->can('respond', $record), 403);

                ConsultationMessage::create([
                    'consultation_id' => $record->id,
                    'sender_type' => SenderType::Manager,
                    'message' => $data['message'],
                ]);

                $actor = auth()->user();
                if ($record->manager_id === null && $actor->isManager()) {
                    $record->update(['manager_id' => $actor->id]);
                }

                $record->touch();

                Notification::make()->title('Balasan terkirim')->success()->send();
            });
    }

    /**
     * Mark an open thread as a deal (open → deal).
     */
    protected function markDealAction(): Actions\Action
    {
        return Actions\Action::make('tandaiDeal')
            ->label('Tandai Deal')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Consultation $record): bool => $record->status === ConsultationStatus::Open
                && auth()->user()->can('update', $record))
            ->action(function (Consultation $record): void {
                abort_unless(auth()->user()->can('update', $record), 403);
                $record->update(['status' => ConsultationStatus::Deal]);
                Notification::make()->title('Konsultasi ditandai Deal')->success()->send();
            });
    }

    /**
     * Close a thread (open|deal → closed). A closed thread becomes read-only.
     */
    protected function closeAction(): Actions\Action
    {
        return Actions\Action::make('tutup')
            ->label('Tutup')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Consultation $record): bool => ! $record->isClosed()
                && auth()->user()->can('update', $record))
            ->action(function (Consultation $record): void {
                abort_unless(auth()->user()->can('update', $record), 403);
                $record->update(['status' => ConsultationStatus::Closed]);
                Notification::make()->title('Konsultasi ditutup')->success()->send();
            });
    }
}
