<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test reaches a real HTTP endpoint unless it opts in (mail-truth does, see tests/Pest.php).
        Http::preventStrayRequests();
    }
}
