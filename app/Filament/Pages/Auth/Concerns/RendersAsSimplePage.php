<?php

namespace App\Filament\Pages\Auth\Concerns;

use Filament\Pages\Concerns\HasMaxWidth;
use Filament\Pages\Concerns\HasTopbar;
use Filament\Pages\Page;

/**
 * Renders a routable {@see Page} with the centered, chrome-less
 * "simple" layout used by the login screen — so the 2FA enrollment/challenge
 * pages look like auth screens while still owning a panel route (which a bare
 * SimplePage does not).
 */
trait RendersAsSimplePage
{
    use HasMaxWidth;
    use HasTopbar;

    public function getLayout(): string
    {
        return 'filament-panels::components.layout.simple';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxWidth' => $this->getMaxWidth(),
        ];
    }

    public function hasLogo(): bool
    {
        return true;
    }
}
