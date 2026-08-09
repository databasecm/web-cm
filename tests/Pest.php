<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Browser tests (tests/Browser) run in a real headless Chrome via Dusk. The
| served app and the browser share one real database, so transactional
| RefreshDatabase cannot be used. We use DatabaseTruncation (a clean DB per test
| by truncating tables) rather than DatabaseMigrations — the latter runs
| migrate:rollback after every test, coupling Dusk stability to the correctness
| of all 47 migrations' down() methods. Truncation gives the same isolation
| without that coupling.
|
*/

pest()->extend(DuskTestCase::class)
    ->use(DatabaseTruncation::class)
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
