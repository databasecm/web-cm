<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial RAB default settings (ADR-0006). Idempotent — values may be
 * changed afterwards through the Filament settings page.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingService::DEFAULTS as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
