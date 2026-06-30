<?php

/*
|--------------------------------------------------------------------------
| Company identity (kop surat)
|--------------------------------------------------------------------------
|
| Branding used on generated documents such as the RAB penawaran PDF
| (Fase 2B-9). Kept here so the letterhead is a single source of truth and
| can later move to the settings table without touching the templates.
|
*/

return [
    'name' => env('COMPANY_NAME', 'CV. Cimandiri'),
    'tagline' => env('COMPANY_TAGLINE', 'Build-Tech Solutions'),
    'address' => env('COMPANY_ADDRESS', 'Bogor, Jawa Barat'),
    'phone' => env('COMPANY_PHONE', ''),
    'email' => env('COMPANY_EMAIL', ''),

    // Optional letterhead logo: absolute path or public path. Rendered only
    // when the file exists, so the template stays safe when it is unset.
    'logo_path' => env('COMPANY_LOGO_PATH', ''),
];
