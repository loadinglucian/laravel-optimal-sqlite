<?php

declare(strict_types=1);

namespace Loadinglucian\LaravelOptimalSqlite\Commands;

use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use Loadinglucian\LaravelOptimalSqlite\SQLiteDatabaseOptimizer;

/**
 * Runs Laravel migrations after preparing empty SQLite database files.
 */
final class MigrateCommand extends BaseMigrateCommand
{
    /**
     * Optimize eligible SQLite files, then let Laravel prepare its repository.
     */
    #[\Override]
    protected function prepareDatabase()
    {
        $database = $this->option('database');

        $this->laravel
            ->make(SQLiteDatabaseOptimizer::class)
            ->optimizeConfiguredEmptyDatabases(is_string($database) && $database !== '' ? $database : null);

        parent::prepareDatabase();
    }
}
