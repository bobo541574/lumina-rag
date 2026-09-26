<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of storage/logs: the rag and security channels
        // are real daily files that the log viewer reads.
        $null = config('logging.channels.null');
        config([
            'logging.default' => 'null',
            'logging.channels.rag' => $null,
            'logging.channels.security' => $null,
        ]);
    }
}
