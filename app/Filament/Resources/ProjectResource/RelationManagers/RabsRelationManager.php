<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\RabStatus;
use App\Models\Ahsap;
use App\Models\Project;
use App\Models\Rab;
use App\Services\RabBuilder;
use App\Services\RabPenawaranPdf;
use App\Services\SettingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manager surface for building a project's RAB versions from AHSAP (Fase 2B-4).
 *
 * "Buat RAB" picks AHSAP items in the project's bidang, takes volumes and
 * (optionally overridden) rates, shows a live grand_total preview, and builds a
 * frozen version via {@see RabBuilder} (ADR-0007). Building always creates a new
 * version; an approved RAB is never edited in place.
 */
class RabsRelationManager extends RelationManager
{
    protected static string $relationship = 'rabs';

    protected static ?string $title = 'RAB';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')->label('Versi')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (RabStatus $state): string => $state->label())
                    ->color(fn (RabStatus $state): string => match ($state) {
                        RabStatus::Draft => 'gray',
                        RabStatus::Submitted => 'warning',
                        RabStatus::Approved => 'success',
                    }),
                Tables\Columns\TextColumn::make('total_material')->label('Material')->money('IDR'),
                Tables\Columns\TextColumn::make('total_upah')->label('Upah')->money('IDR'),
                Tables\Columns\TextColumn::make('grand_total')->label('Grand Total')->money('IDR')->weight('bold'),
            ])
            ->actions([
                // Download the penawaran PDF — only for a submitted/approved RAB,
                // and only for staff who may view it (Manager in its bidang).
                Tables\Actions\Action::make('unduhPenawaran')
                    ->label('Unduh Penawaran')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (Rab $record): bool => auth()->user()->can('downloadPdf', $record))
                    ->action(function (Rab $record) {
                        $pdf = app(RabPenawaranPdf::class);

                        return response()->streamDownload(
                            fn () => print ($pdf->make($record)->output()),
                            $pdf->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('buatRab')
                    ->label('Buat RAB')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()->can('create', Rab::class))
                    ->form(fn (): array => $this->buildForm())
                    ->action(function (array $data): void {
                        /** @var Project $project */
                        $project = $this->getOwnerRecord();

                        app(RabBuilder::class)->build($project, $data['items'] ?? [], [
                            'margin_percent' => $data['margin_percent'],
                            'ppn_percent' => $data['ppn_percent'],
                            'overhead_percent' => $data['overhead_percent'],
                        ]);

                        Notification::make()->title('RAB dibuat.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * @return array<int, Component>
     */
    protected function buildForm(): array
    {
        $settings = app(SettingService::class);
        $ahsapOptions = $this->ahsapOptions();

        return [
            Forms\Components\Repeater::make('items')
                ->label('Item Pekerjaan (AHSAP)')
                ->live()
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('ahsap_id')
                        ->label('AHSAP')
                        ->options($ahsapOptions)
                        ->searchable()
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('volume')->label('Volume')->numeric()->default(1)->required()->live(onBlur: true),
                ]),

            Forms\Components\Section::make('Tarif')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('overhead_percent')->label('Overhead (%)')->numeric()->required()
                        ->default($settings->overheadPercentDefault())->live(onBlur: true),
                    Forms\Components\TextInput::make('margin_percent')->label('Margin (%)')->numeric()->required()
                        ->default($settings->marginPercentDefault())->live(onBlur: true),
                    Forms\Components\TextInput::make('ppn_percent')->label('PPN (%)')->numeric()->required()
                        ->default($settings->ppnPercentDefault())->live(onBlur: true),
                ]),

            Forms\Components\Placeholder::make('grand_total_preview')
                ->label('Perkiraan Grand Total')
                ->content(fn (Get $get): string => 'Rp '.$this->previewGrandTotal($get)),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function ahsapOptions(): array
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();

        return Ahsap::query()
            ->where('bidang', $project->bidang?->value)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Ahsap $a): array => [
                $a->id => "{$a->name} — Rp ".number_format((float) $a->base_price, 0, ',', '.'),
            ])
            ->all();
    }

    /**
     * Live preview of the grand total from the current form state (display only;
     * the authoritative figures are computed by RabBuilder on save).
     */
    protected function previewGrandTotal(Get $get): string
    {
        $base = BigDecimal::zero();

        foreach ($get('items') ?? [] as $row) {
            $ahsap = Ahsap::find($row['ahsap_id'] ?? null);
            $volume = $row['volume'] ?? null;

            if ($ahsap === null || ! is_numeric($volume)) {
                continue;
            }

            $base = $base->plus(BigDecimal::of((string) $volume)->multipliedBy((string) $ahsap->base_price));
        }

        $overhead = $this->pct($base, $get('overhead_percent'));
        $margin = $this->pct($base->plus($overhead), $get('margin_percent'));
        $ppn = $this->pct($base->plus($overhead)->plus($margin), $get('ppn_percent'));
        $grand = $base->plus($overhead)->plus($margin)->plus($ppn);

        return number_format((float) (string) $grand->toScale(2, RoundingMode::HALF_UP), 2, ',', '.');
    }

    private function pct(BigDecimal $amount, mixed $percent): BigDecimal
    {
        if (! is_numeric($percent)) {
            return BigDecimal::zero();
        }

        return $amount->multipliedBy(BigDecimal::of((string) $percent)->dividedBy('100', 10, RoundingMode::HALF_UP))
            ->toScale(2, RoundingMode::HALF_UP);
    }
}
