<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        \Tests\Support\TestDatabaseSafety::assertSafe($app);

        return $app;
    }
}
