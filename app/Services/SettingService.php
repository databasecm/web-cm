<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached accessor for application settings (ADR-0006).
 *
 * The whole settings set is cached under one key; writing any setting through
 * set() invalidates it. Typed accessors expose the RAB defaults with sensible
 * fallbacks so the system is usable even before the settings are seeded. Values
 * are percentage strings (e.g. "11") so they feed BigDecimal cleanly (ADR-0005).
 */
class SettingService
{
    public const KEY_MARGIN = 'rab.margin_percent';

    public const KEY_PPN = 'rab.ppn_percent';

    public const KEY_OVERHEAD = 'rab.overhead_percent';

    private const CACHE_KEY = 'settings.all';

    /** Sensible fallbacks used when a setting row is absent. */
    public const DEFAULTS = [
        self::KEY_MARGIN => '10',
        self::KEY_PPN => '11',
        self::KEY_OVERHEAD => '5',
    ];

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public function marginPercentDefault(): string
    {
        return (string) $this->get(self::KEY_MARGIN);
    }

    public function ppnPercentDefault(): string
    {
        return (string) $this->get(self::KEY_PPN);
    }

    public function overheadPercentDefault(): string
    {
        return (string) $this->get(self::KEY_OVERHEAD);
    }

    /**
     * The whole settings map (key => value), cached until a write invalidates it.
     *
     * @return array<string, string|null>
     */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
    }
}
