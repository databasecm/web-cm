<?php

namespace App\Filament\Resources\ConsultationResource\Pages;

use App\Enums\ConsultationStatus;
use App\Enums\SenderType;
use App\Exceptions\DealConversionException;
use App\Exceptions\ProjectConversionException;
use App\Filament\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Project;
use App\Policies\ConsultationPolicy;
use App\Services\DealCustomerService;
use App\Services\ProjectFromDealService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

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
            $this->createCustomerAction(),
            $this->createProjectAction(),
            $this->closeAction(),
        ];
    }

    /**
     * Create a draft project from a deal whose consumer already has an account
     * (Fase 2B bridge). Authorized by the narrow createProjectForDeal gate;
     * visible only while the thread is a deal, has a consumer account, and has
     * not already spawned a project (one project per deal).
     */
    protected function createProjectAction(): Actions\Action
    {
        return Actions\Action::make('buatProyek')
            ->label('Buat Proyek')
            ->icon('heroicon-o-briefcase')
            ->color('primary')
            ->visible(fn (Consultation $record): bool => $record->status === ConsultationStatus::Deal
                && $record->konsumen_id !== null
                && ! Project::query()->where('consultation_id', $record->id)->exists()
                && auth()->user()->can('createProjectForDeal', $record->bidang?->value))
            ->form([
                Forms\Components\TextInput::make('title')
                    ->label('Judul Proyek')
                    ->maxLength(255)
                    ->placeholder('Kosongkan untuk judul otomatis'),
            ])
            ->action(function (array $data, Consultation $record): void {
                Gate::authorize('createProjectForDeal', $record->bidang?->value);

                try {
                    app(ProjectFromDealService::class)->create($record, auth()->user(), $data['title'] ?? null);
                } catch (ProjectConversionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Proyek draft dibuat dari deal.')->success()->send();
            });
    }

    /**
     * Create a consumer account for a deal consultation that has none (the
     * login-source edge of B5). Authorized by the narrow createCustomerForDeal
     * gate; visible only while the thread is a deal without an account.
     */
    protected function createCustomerAction(): Actions\Action
    {
        return Actions\Action::make('buatAkunKonsumen')
            ->label('Buat Akun Konsumen')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (Consultation $record): bool => $record->status === ConsultationStatus::Deal
                && $record->konsumen_id === null
                && auth()->user()->can('createCustomerForDeal', $record->bidang?->value))
            ->form([
                Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
                Forms\Components\TextInput::make('phone')->label('No. Telepon')->tel()->maxLength(30),
                Forms\Components\TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
            ])
            ->action(function (array $data, Consultation $record): void {
                Gate::authorize('createCustomerForDeal', $record->bidang?->value);

                try {
                    app(DealCustomerService::class)->fromConsultation($record, $data, auth()->user());
                } catch (DealConversionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Akun konsumen dibuat.')->success()->send();
            });
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
