<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.default');
        $dbName = config("database.connections.{$database}.database");

        if ($dbName === 'warehouseops' && app()->environment('testing')) {
            throw new \RuntimeException(
                'REFUSING TO RUN TESTS: Test database is "warehouseops" (production). '
                .'Tests must use "warehouseops_test". Check phpunit.xml and .env.testing.'
            );
        }

        $this->withoutVite();
    }
}
