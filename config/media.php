<?php

use App\Models\Bast;
use App\Models\Design;

/*
|--------------------------------------------------------------------------
| Media storage (ADR-0015)
|--------------------------------------------------------------------------
|
| One place configures the whole media mechanism. Binary uploads land on the
| `disk` below (a PRIVATE local disk in dev/test; flip MEDIA_DISK=s3 at go-live —
| config only, no code change, A3-style). Files are NEVER served by a naked disk
| URL: every download goes through a short-lived signed route that re-checks the
| owning module's policy (MediaController). Signature = freshness; policy =
| authorization — both layers.
|
| All validation limits are here (never hardcoded), so field devices uploading
| large phone video only need a config bump.
|
*/

return [
    // The filesystem disk media lives on. Local private now; set MEDIA_DISK=s3
    // (the existing s3 disk) at go-live — nothing else changes.
    'disk' => env('MEDIA_DISK', 'media'),

    // Signed download URL lifetime, seconds (short — the link is re-issued per view).
    'url_ttl' => (int) env('MEDIA_URL_TTL', 300),

    /*
     | Validation profiles — allowed MIME types and max size (KB) per kind.
     | Enforced SERVER-SIDE by MediaService (never trust the client). Raise the
     | video ceiling via env for field footage.
     */
    'profiles' => [
        'image' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_kb' => (int) env('MEDIA_MAX_IMAGE_KB', 5120),    // 5 MB
        ],
        'document' => [
            'mimes' => ['application/pdf'],
            'max_kb' => (int) env('MEDIA_MAX_DOCUMENT_KB', 10240), // 10 MB
        ],
        'video' => [
            'mimes' => ['video/mp4', 'video/quicktime'],
            'max_kb' => (int) env('MEDIA_MAX_VIDEO_KB', 51200),   // 50 MB
        ],
    ],

    /*
     | Media-bearing models, keyed by a stable URL alias. The signed route carries
     | the alias; MediaController resolves it back to the model class. Each module
     | is added here as its media task lands (designs first, Fase media-1).
     */
    'models' => [
        'design' => Design::class,
        'bast' => Bast::class,
    ],
];
