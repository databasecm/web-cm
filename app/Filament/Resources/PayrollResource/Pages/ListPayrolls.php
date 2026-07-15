<?php

namespace App\Filament\Resources\PayrollResource\Pages;

use App\Filament\Resources\PayrollResource;
use App\Models\Payroll;
use App\Services\PayrollService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListPayrolls extends ListRecords
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Generate/refresh a weekly draft — HR + overseers (generatePayroll
            // gate). Finance cannot generate (SoD). Defaults to the current
            // Mon–Sat week; idempotent per (period, type) via the service.
            Actions\Action::make('generate')
                ->label('Generate Payroll')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => auth()->user()->can('generatePayroll', Payroll::class))
                ->form([
                    Forms\Components\DatePicker::make('period_start')
                        ->label('Mulai (Senin)')
                        ->native(false)
                        ->required()
                        ->default(Carbon::now()->startOfWeek(Carbon::MONDAY)),
                    Forms\Components\DatePicker::make('period_end')
                        ->label('Selesai (Sabtu)')
                        ->native(false)
                        ->required()
                        ->default(Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(5)),
                ])
                ->action(function (array $data): void {
                    app(PayrollService::class)->generate(
                        Carbon::parse($data['period_start'])->toDateString(),
                        Carbon::parse($data['period_end'])->toDateString(),
                        auth()->id(),
                    );
                    Notification::make()->title('Payroll digenerate — slip gaji per karyawan siap ditinjau.')->success()->send();
                }),
        ];
    }
}
