<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Loadinglucian\LaravelOptimalSqlite\SQLiteDatabaseOptimizer;
use Loadinglucian\LaravelOptimalSqlite\Tests\TestCase;

it('automatically optimizes configured SQLite databases before migrations create tables', function (): void {
    // ARRANGE
    /** @var TestCase $this */
    $connection = 'optimized_'.Str::random(8);
    $secondConnection = 'optimized_'.Str::random(8);
    $databasePath = temporarySqliteDatabasePath();
    $secondDatabasePath = temporarySqliteDatabasePath();
    $migrationPath = realpath(__DIR__.'/Fixtures/migrations');

    configureMinimalSqliteConnection($connection, $databasePath);
    configureMinimalSqliteConnection($secondConnection, $secondDatabasePath);
    config()->set('database.default', $connection);

    try {
        // ACT
        $this->artisan('migrate', [
            '--path' => $migrationPath,
            '--realpath' => true,
            '--force' => true,
        ])->assertSuccessful();

        // ASSERT
        expect(sqlitePragma($databasePath, 'page_size'))->toBe('32768')
            ->and(sqlitePragma($databasePath, 'auto_vacuum'))->toBe('2')
            ->and(sqlitePragma($databasePath, 'journal_mode'))->toBe('wal')
            ->and(sqlitePragma($secondDatabasePath, 'page_size'))->toBe('32768')
            ->and(sqlitePragma($secondDatabasePath, 'auto_vacuum'))->toBe('2')
            ->and(sqlitePragma($secondDatabasePath, 'journal_mode'))->toBe('wal')
            ->and(sqliteConnectionPragma($connection, 'busy_timeout'))->toBe('5000')
            ->and(sqliteConnectionPragma($connection, 'cache_size'))->toBe('-20000')
            ->and(sqliteConnectionPragma($connection, 'foreign_keys'))->toBe('1')
            ->and((int) sqliteConnectionPragma($connection, 'mmap_size'))->toBeGreaterThanOrEqual(2147418112)
            ->and(sqliteConnectionPragma($connection, 'temp_store'))->toBe('2')
            ->and(sqliteConnectionPragma($connection, 'synchronous'))->toBe('1');
    } finally {
        DB::purge($connection);
        DB::purge($secondConnection);
        removeSqliteDatabaseFiles($databasePath);
        removeSqliteDatabaseFiles($secondDatabasePath);
    }
});

it('keeps optimized SQLite databases compatible with repeated migrate fresh runs', function (): void {
    // ARRANGE
    /** @var TestCase $this */
    $connection = 'optimized_'.Str::random(8);
    $databasePath = temporarySqliteDatabasePath();
    $migrationPath = realpath(__DIR__.'/Fixtures/migrations');

    configureSqliteConnection($connection, $databasePath);

    try {
        // ACT & ASSERT
        for ($run = 1; $run <= 2; $run++) {
            $this->artisan('migrate:fresh', [
                '--database' => $connection,
                '--path' => $migrationPath,
                '--realpath' => true,
                '--force' => true,
            ])->assertSuccessful();

            expect(DB::connection($connection)->table('widgets')->count())->toBe(0)
                ->and(sqlitePragma($databasePath, 'integrity_check'))->toBe('ok')
                ->and(sqlitePragma($databasePath, 'page_size'))->toBe('32768')
                ->and(sqlitePragma($databasePath, 'journal_mode'))->toBe('wal');

            DB::purge($connection);
        }
    } finally {
        DB::purge($connection);
        removeSqliteDatabaseFiles($databasePath);
    }
});

it('refuses to rewrite an existing SQLite database unless explicitly forced', function (): void {
    // ARRANGE
    $connection = 'optimized_'.Str::random(8);
    $databasePath = temporarySqliteDatabasePath();
    $filesystem = new Filesystem;

    $filesystem->put($databasePath, '');
    $pdo = new PDO("sqlite:{$databasePath}");
    $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

    $service = new SQLiteDatabaseOptimizer($filesystem);

    try {
        // ACT & ASSERT
        expect(fn () => $service->optimize($connection, [
            'driver' => 'sqlite',
            'database' => $databasePath,
        ]))->toThrow(RuntimeException::class, 'Refusing to rewrite SQLite file settings');
    } finally {
        removeSqliteDatabaseFiles($databasePath);
    }
});

/**
 * Build a unique temporary SQLite database path for an isolated test run.
 */
function temporarySqliteDatabasePath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'optimal-sqlite-'.Str::uuid().'.sqlite';
}

/**
 * Register a SQLite test connection with the full optimized runtime settings.
 */
function configureSqliteConnection(string $connection, string $databasePath): void
{
    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
        'transaction_mode' => 'DEFERRED',
        'pragmas' => [
            'cache_size' => -20000,
            'mmap_size' => 2147483648,
            'temp_store' => 'MEMORY',
        ],
    ]);
}

/**
 * Register a minimal SQLite test connection so package defaults must fill the gaps.
 */
function configureMinimalSqliteConnection(string $connection, string $databasePath): void
{
    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
    ]);
}

/**
 * Read a PRAGMA value from a database file using a direct PDO connection.
 */
function sqlitePragma(string $databasePath, string $pragma): string
{
    $pdo = new PDO("sqlite:{$databasePath}");
    $value = $pdo->query("PRAGMA {$pragma}")?->fetchColumn();

    if ($value === false) {
        throw new RuntimeException("Unable to read SQLite PRAGMA [{$pragma}].");
    }

    return (string) $value;
}

/**
 * Read a PRAGMA value through Laravel's configured database connection.
 */
function sqliteConnectionPragma(string $connection, string $pragma): string
{
    $value = DB::connection($connection)->selectOne("PRAGMA {$pragma}");

    if (! is_object($value)) {
        throw new RuntimeException("Unable to read SQLite PRAGMA [{$pragma}] for connection [{$connection}].");
    }

    $values = get_object_vars($value);
    $first = reset($values);

    if ($first === false) {
        throw new RuntimeException("SQLite PRAGMA [{$pragma}] did not return a value for connection [{$connection}].");
    }

    return (string) $first;
}

/**
 * Delete a temporary SQLite database and its WAL sidecar files.
 */
function removeSqliteDatabaseFiles(string $databasePath): void
{
    $filesystem = new Filesystem;

    foreach ([$databasePath, "{$databasePath}-wal", "{$databasePath}-shm"] as $path) {
        if ($filesystem->exists($path)) {
            $filesystem->delete($path);
        }
    }
}
