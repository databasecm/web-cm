<?php

namespace App\Filament\Pages;

use App\Models\Role;
use App\Services\SettingService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Global RAB defaults — margin, PPN and overhead percentages (ADR-0006).
 *
 * Managed ONLY by Owner/Direktur; a Manager (or anyone else) cannot change the
 * global defaults. Values are read everywhere through SettingService; changing
 * them here invalidates its cache and never touches RABs already built (those
 * snapshot their own rates).
 */
class RabSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Pengaturan RAB';

    protected static ?string $title = 'Pengaturan RAB (Margin & PPN)';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static string $view = 'filament.pages.rab-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill([
            'margin_percent' => $settings->marginPercentDefault(),
            'ppn_percent' => $settings->ppnPercentDefault(),
            'overhead_percent' => $settings->overheadPercentDefault(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Default Global RAB')
                    ->description('Dipakai sebagai nilai awal RAB; tiap RAB dapat menimpanya, dan RAB yang sudah dibuat tidak terpengaruh.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('margin_percent')->label('Margin (%)')->numeric()->required()->minValue(0),
                        TextInput::make('ppn_percent')->label('PPN (%)')->numeric()->required()->minValue(0),
                        TextInput::make('overhead_percent')->label('Overhead (%)')->numeric()->required()->minValue(0),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->form->getState();

        $settings->set(SettingService::KEY_MARGIN, (string) $data['margin_percent']);
        $settings->set(SettingService::KEY_PPN, (string) $data['ppn_percent']);
        $settings->set(SettingService::KEY_OVERHEAD, (string) $data['overhead_percent']);

        Notification::make()->title('Pengaturan RAB disimpan.')->success()->send();
    }
}
