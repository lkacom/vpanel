<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Browser CSRF protection remains enabled in production. Feature tests
        // use framework request helpers that intentionally omit browser tokens.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
