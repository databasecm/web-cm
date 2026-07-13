<?php

namespace App\Enums;

/**
 * Kind of media attached to a daily field report (ERD §A.5).
 */
enum ReportMediaType: string
{
    case Photo = 'photo';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Foto',
            self::Video => 'Video',
        };
    }
}
