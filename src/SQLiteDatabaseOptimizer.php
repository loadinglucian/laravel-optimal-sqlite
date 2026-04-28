<?php

declare(strict_types=1);

namespace Loadinglucian\LaravelOptimalSqlite;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Loadinglucian\LaravelOptimalSqlite\ValueObjects\SQLiteOptimizationResult;
use PDO;
use RuntimeException;

/**
 * Applies and verifies file-level SQLite tuning before migrations create schema.
 */
final readonly class SQLiteDatabaseOptimizer
{
    private const int TARGET_PAGE_SIZE = 32768;

    private const int TARGET_AUTO_VACUUM = 2;

    /**
     * Create an optimizer that can create missing SQLite database files.
     */
    public function __construct(
        private Filesystem $filesystem,
    ) {}

    /**
     * Optimize one SQLite connection and fail if it cannot be safely tuned.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    public function optimize(string $connection, array $connectionConfig, bool $allowExistingTables = false): SQLiteOptimizationResult
    {
        $result = $this->optimizeConnection(
            connection: $connection,
            connectionConfig: $connectionConfig,
            allowExistingTables: $allowExistingTables,
            skipExistingTables: false,
        );

        if (! $result instanceof SQLiteOptimizationResult) {
            throw new RuntimeException("SQLite connection [{$connection}] was not optimized.");
        }

        return $result;
    }

    /**
     * Optimize configured file-backed SQLite databases that are still empty.
     *
     * @return array<string, SQLiteOptimizationResult>
     */
    public function optimizeConfiguredEmptyDatabases(?string $onlyConnection = null): array
    {
        $results = [];

        foreach ($this->targetConnections($onlyConnection) as $connection => $config) {
            DB::purge($connection);

            $result = $this->optimizeConnection(
                connection: $connection,
                connectionConfig: $config,
                allowExistingTables: false,
                skipExistingTables: true,
            );

            DB::purge($connection);

            if ($result instanceof SQLiteOptimizationResult) {
                $results[$connection] = $result;
            }
        }

        return $results;
    }

    /**
     * Run the shared optimization workflow for a single SQLite connection.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    private function optimizeConnection(
        string $connection,
        array $connectionConfig,
        bool $allowExistingTables,
        bool $skipExistingTables,
    ): ?SQLiteOptimizationResult {
        if ('sqlite' !== ($connectionConfig['driver'] ?? null)) {
            throw new InvalidArgumentException("The [{$connection}] database connection is not SQLite.");
        }

        $databasePath = $this->databasePath($connectionConfig);

        $databaseFileCreated = $this->ensureDatabaseFileExists($databasePath);
        $pdo = $this->connect($databasePath);
        $tableCountBeforeOptimization = $this->tableCount($pdo);

        if (! $allowExistingTables && $tableCountBeforeOptimization !== 0) {
            if ($skipExistingTables) {
                return null;
            }

            throw new RuntimeException('Refusing to rewrite SQLite file settings on a database that already has tables. Take a backup, then call SQLiteDatabaseOptimizer::optimize() with $allowExistingTables set to true.');
        }

        $this->applyFilePragmas($pdo, $connectionConfig);

        $result = $this->result(
            connection: $connection,
            databasePath: $databasePath,
            databaseFileCreated: $databaseFileCreated,
            tableCountBeforeOptimization: $tableCountBeforeOptimization,
            pdo: $pdo,
        );

        $this->assertOptimized($result, $connectionConfig);

        return $result;
    }

    /**
     * Resolve the configured SQLite connections that should receive optimization.
     *
     * @return array<string, array<string, mixed>>
     */
    private function targetConnections(?string $onlyConnection): array
    {
        $connections = config('database.connections');

        if (! is_array($connections)) {
            return [];
        }

        $targets = [];

        foreach ($connections as $name => $config) {
            if (! is_string($name) || ! is_array($config) || 'sqlite' !== ($config['driver'] ?? null)) {
                continue;
            }

            if (is_string($onlyConnection) && $onlyConnection !== '' && $name !== $onlyConnection) {
                continue;
            }

            /** @var array<string, mixed> $config */
            if (! $this->isFileBackedSqliteConnection($config)) {
                continue;
            }

            $targets[$name] = OptimalSqliteServiceProvider::applyRuntimeDefaults($name, $config);
        }

        return $targets;
    }

    /**
     * Determine whether a SQLite connection uses an on-disk database file.
     *
     * @param  array<string, mixed>  $config
     */
    private function isFileBackedSqliteConnection(array $config): bool
    {
        $database = $config['database'] ?? null;

        return is_string($database)
            && $database !== ''
            && $database !== ':memory:'
            && ! str_contains($database, '?mode=memory')
            && ! str_contains($database, '&mode=memory');
    }

    /**
     * Resolve a configured SQLite database path to an absolute filesystem path.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    private function databasePath(array $connectionConfig): string
    {
        $database = $connectionConfig['database'] ?? '';

        if (! is_string($database) || $database === '' || $database === ':memory:' || str_contains($database, '?mode=memory') || str_contains($database, '&mode=memory')) {
            throw new InvalidArgumentException('SQLite optimization requires a file-backed database, not an in-memory database.');
        }

        if (str_starts_with($database, DIRECTORY_SEPARATOR)) {
            return $database;
        }

        return base_path($database);
    }

    /**
     * Create the SQLite database file and parent directory when missing.
     */
    private function ensureDatabaseFileExists(string $databasePath): bool
    {
        if ($this->filesystem->exists($databasePath)) {
            return false;
        }

        $directory = dirname($databasePath);

        if (! $this->filesystem->exists($directory)) {
            $this->filesystem->makeDirectory($directory, recursive: true);
        }

        $this->filesystem->put($databasePath, '');

        return true;
    }

    /**
     * Open a direct PDO connection for file-level PRAGMAs before Laravel connects.
     */
    private function connect(string $databasePath): PDO
    {
        $pdo = new PDO("sqlite:{$databasePath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Apply SQLite settings that affect the database file format.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    private function applyFilePragmas(PDO $pdo, array $connectionConfig): void
    {
        $pdo->exec('PRAGMA journal_mode = DELETE;');
        $pdo->exec('PRAGMA auto_vacuum = INCREMENTAL;');
        $pdo->exec('PRAGMA page_size = '.self::TARGET_PAGE_SIZE.';');
        $pdo->exec('VACUUM;');
        $pdo->exec('PRAGMA journal_mode = '.$this->journalMode($connectionConfig).';');
    }

    /**
     * Read the post-optimization SQLite settings into a typed result object.
     */
    private function result(
        string $connection,
        string $databasePath,
        bool $databaseFileCreated,
        int $tableCountBeforeOptimization,
        PDO $pdo,
    ): SQLiteOptimizationResult {
        return new SQLiteOptimizationResult(
            connection: $connection,
            databasePath: $databasePath,
            databaseFileCreated: $databaseFileCreated,
            tableCountBeforeOptimization: $tableCountBeforeOptimization,
            pageSize: $this->intPragma($pdo, 'page_size'),
            autoVacuum: $this->intPragma($pdo, 'auto_vacuum'),
            journalMode: strtolower($this->stringPragma($pdo, 'journal_mode')),
        );
    }

    /**
     * Fail fast when SQLite did not persist the file-level settings we require.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    private function assertOptimized(SQLiteOptimizationResult $result, array $connectionConfig): void
    {
        if ($result->pageSize !== self::TARGET_PAGE_SIZE) {
            throw new RuntimeException('Expected SQLite page_size ['.self::TARGET_PAGE_SIZE."], got [{$result->pageSize}].");
        }

        if ($result->autoVacuum !== self::TARGET_AUTO_VACUUM) {
            throw new RuntimeException('Expected SQLite auto_vacuum ['.self::TARGET_AUTO_VACUUM."], got [{$result->autoVacuum}].");
        }

        $journalMode = strtolower($this->journalMode($connectionConfig));

        if ($journalMode !== $result->journalMode) {
            throw new RuntimeException("Expected SQLite journal_mode [{$journalMode}], got [{$result->journalMode}].");
        }
    }

    /**
     * Resolve the configured journal mode used after file-level rewrites.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    private function journalMode(array $connectionConfig): string
    {
        $journalMode = $connectionConfig['journal_mode'] ?? null;

        if (! is_string($journalMode) || $journalMode === '') {
            throw new RuntimeException('SQLite journal_mode must be configured before optimization.');
        }

        return $journalMode;
    }

    /**
     * Count user-created schema tables in the SQLite file.
     */
    private function tableCount(PDO $pdo): int
    {
        return $this->intQuery($pdo, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
    }

    /**
     * Read an integer-valued SQLite PRAGMA.
     */
    private function intPragma(PDO $pdo, string $pragma): int
    {
        return $this->intQuery($pdo, "PRAGMA {$pragma}");
    }

    /**
     * Read a string-valued SQLite PRAGMA.
     */
    private function stringPragma(PDO $pdo, string $pragma): string
    {
        $statement = $pdo->query("PRAGMA {$pragma}");

        if ($statement === false) {
            throw new RuntimeException("Unable to read SQLite PRAGMA [{$pragma}].");
        }

        $value = $statement->fetchColumn();

        if ($value === false) {
            throw new RuntimeException("SQLite PRAGMA [{$pragma}] did not return a value.");
        }

        return (string) $value;
    }

    /**
     * Run a scalar SQLite query and cast the returned value to an integer.
     */
    private function intQuery(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new RuntimeException("Unable to run SQLite query [{$sql}].");
        }

        $value = $statement->fetchColumn();

        if ($value === false) {
            throw new RuntimeException("SQLite query [{$sql}] did not return a value.");
        }

        return (int) $value;
    }
}
