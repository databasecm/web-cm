<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid media upload (ADR-0015) — wrong type or oversize. Carries
 * an HTTP-ish status so API callers get 422.
 */
class MediaException extends RuntimeException
{
    public static function unsupportedType(string $mime): self
    {
        return new self("Tipe berkas tidak didukung: {$mime}.");
    }

    public static function tooLarge(int $kb, int $maxKb): self
    {
        return new self("Ukuran berkas {$kb} KB melebihi batas {$maxKb} KB.");
    }

    public static function empty(): self
    {
        return new self('Berkas kosong atau gagal diunggah.');
    }
}
