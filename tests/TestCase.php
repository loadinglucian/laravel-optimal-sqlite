<?php

declare(strict_types=1);

namespace Loadinglucian\LaravelOptimalSqlite\Tests;

use Loadinglucian\LaravelOptimalSqlite\OptimalSqliteServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Boots a minimal Laravel application for package tests.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Register the package provider for each test application.
     *
     * @return list<class-string>
     */
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [
            OptimalSqliteServiceProvider::class,
        ];
    }
}
