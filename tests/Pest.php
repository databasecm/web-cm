<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Browser tests (tests/Browser) run in a real headless Chrome via Dusk and use
| DatabaseMigrations (a fresh migration per test — the served app and the browser
| share one real database, so transactional RefreshDatabase cannot be used).
| Feature/Unit tests use the transactional TestCase.
|
*/

pest()->extend(DuskTestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Browser');

pest()->extend(TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function something()
{
    // ..
}
