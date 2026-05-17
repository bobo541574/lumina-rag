<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Placeholder helper for demonstration purposes
 *
 * Exists only as an example in the Pest bootstrap file.
 * Not used by any test suite.
 */
function something(): void
{
    // ..
}
