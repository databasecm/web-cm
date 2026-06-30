<?php

use App\Models\Setting;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SettingService;
});

// ---------------------------------------------------------------------------
// Defaults & fallbacks
// ---------------------------------------------------------------------------

it('falls back to sensible RAB defaults when nothing is seeded', function () {
    expect($this->service->marginPercentDefault())->toBe('10')
        ->and($this->service->ppnPercentDefault())->toBe('11')
        ->and($this->service->overheadPercentDefault())->toBe('5');
});

it('reads seeded values over the fallbacks', function () {
    $this->seed(SettingSeeder::class);
    Setting::updateOrCreate(['key' => SettingService::KEY_PPN], ['value' => '12']);
    Cache::forget('settings.all');

    expect($this->service->ppnPercentDefault())->toBe('12');
});

// ---------------------------------------------------------------------------
// Cache invalidation on write
// ---------------------------------------------------------------------------

it('invalidates the cache when a setting changes', function () {
    // Prime the cache with the fallback margin.
    expect($this->service->marginPercentDefault())->toBe('10');

    // A raw DB write does NOT invalidate, so the cached value still reads.
    Setting::create(['key' => SettingService::KEY_MARGIN, 'value' => '99']);
    expect($this->service->marginPercentDefault())->toBe('10');

    // Writing through the service invalidates the cache → fresh read.
    $this->service->set(SettingService::KEY_MARGIN, '15');
    expect($this->service->marginPercentDefault())->toBe('15');
});

it('persists a setting change', function () {
    $this->service->set(SettingService::KEY_OVERHEAD, '7');

    expect(Setting::find(SettingService::KEY_OVERHEAD)->value)->toBe('7');
});
