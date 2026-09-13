<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly on any outbound HTTP call that a test has not explicitly
        // faked. Without this, the suite reaches live eMessage / eGovPay /
        // eVerify and fails with provider errors (e.g. HTTP 429) that have
        // nothing to do with the code under test.
        Http::preventStrayRequests();
    }
}
