<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: tests (and RefreshDatabase) must never touch a non-test database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("Refusing to run tests against non-test database [{$database}].");
        }

        return $app;
    }
}
