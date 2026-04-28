<?php

declare(strict_types=1);

namespace Loadinglucian\LaravelOptimalSqlite\ValueObjects;

/**
 * Captures the observed SQLite file settings after an optimization run.
 */
final readonly class SQLiteOptimizationResult
{
    /**
     * Create a snapshot of the database file and persistent PRAGMA values.
     */
    public function __construct(
        public string $connection,
        public string $databasePath,
        public bool $databaseFileCreated,
        public int $tableCountBeforeOptimization,
        public int $pageSize,
        public int $autoVacuum,
        public string $journalMode,
    ) {}
}
