<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Owner Account
    |--------------------------------------------------------------------------
    |
    | Credentials for the single protected Owner account (level 1) created by
    | the OwnerSeeder. These are read from the environment so secrets are never
    | committed. Reading them through config (instead of env() directly in the
    | seeder) keeps seeding correct even when the config cache is warm.
    |
    */

    'owner' => [
        'name' => env('OWNER_NAME', 'Owner'),
        'email' => env('OWNER_EMAIL'),
        'password' => env('OWNER_PASSWORD'),
    ],

];
