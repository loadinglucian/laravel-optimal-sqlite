<?php

declare(strict_types=1);

namespace Loadinglucian\LaravelOptimalSqlite\Commands;

use Illuminate\Database\Connection;
use Illuminate\Database\Console\WipeCommand as BaseWipeCommand;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Wipes SQLite schemas without truncating optimized database files.
 */
final class WipeCommand extends BaseWipeCommand
{
    /**
     * Drop tables while preserving optimized SQLite files when possible.
     */
    #[\Override]
    protected function dropAllTables($database): void
    {
        $connection = DB::connection($database);

        if (! $this->shouldPreserveSqliteFile($connection)) {
            parent::dropAllTables($database);

            return;
        }

        $this->dropSqliteObjects($connection, 'table');
    }

    /**
     * Drop views while preserving optimized SQLite files when possible.
     */
    #[\Override]
    protected function dropAllViews($database): void
    {
        $connection = DB::connection($database);

        if (! $this->shouldPreserveSqliteFile($connection)) {
            parent::dropAllViews($database);

            return;
        }

        $this->dropSqliteObjects($connection, 'view');
    }

    /**
     * Determine whether wiping should drop objects instead of truncating the file.
     */
    private function shouldPreserveSqliteFile(Connection $connection): bool
    {
        $database = $connection->getDatabaseName();

        return $connection->getDriverName() === 'sqlite'
            && $database !== ':memory:'
            && ! str_contains($database, '?mode=memory')
            && ! str_contains($database, '&mode=memory');
    }

    /**
     * Drop SQLite tables or views with foreign key checks disabled temporarily.
     */
    private function dropSqliteObjects(Connection $connection, string $type): void
    {
        $connection->statement('PRAGMA foreign_keys = OFF;');

        try {
            foreach ($this->sqliteObjects($connection, $type) as $object) {
                $connection->statement(sprintf(
                    'DROP %s IF EXISTS "%s"',
                    strtoupper($type),
                    str_replace('"', '""', $object->name),
                ));
            }
        } finally {
            $connection->statement('PRAGMA foreign_keys = ON;');
        }
    }

    /**
     * List SQLite schema object names of the requested type.
     *
     * @return list<stdClass&object{name: string}>
     */
    private function sqliteObjects(Connection $connection, string $type): array
    {
        /** @var list<stdClass&object{name: string}> $objects */
        $objects = array_values($connection->select(
            "SELECT name FROM sqlite_master WHERE type = ? AND name NOT LIKE 'sqlite_%' ORDER BY name",
            [$type],
        ));

        return $objects;
    }
}
