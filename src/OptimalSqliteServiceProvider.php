<?php

declare(strict_types=1);

namespace Loadinglucian\LaravelOptimalSqlite;

use Illuminate\Support\ServiceProvider;
use Loadinglucian\LaravelOptimalSqlite\Commands\MigrateCommand;
use Loadinglucian\LaravelOptimalSqlite\Commands\WipeCommand;

/**
 * Registers SQLite runtime defaults and migration command overrides.
 */
class OptimalSqliteServiceProvider extends ServiceProvider
{
    private const int BUSY_TIMEOUT = 5000;

    private const string JOURNAL_MODE = 'WAL';

    private const string SYNCHRONOUS = 'NORMAL';

    private const int CACHE_SIZE = -20000;

    private const int MMAP_SIZE = 2147483648;

    private const string TEMP_STORE = 'MEMORY';

    /**
     * Register SQLite connection defaults before database connections resolve.
     */
    #[\Override]
    public function register(): void
    {
        $this->configureSqliteConnections();
    }

    /**
     * Register command replacements when the application runs in the console.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MigrateCommand::class,
            WipeCommand::class,
        ]);
    }

    /**
     * Apply runtime defaults to every configured SQLite connection.
     */
    private function configureSqliteConnections(): void
    {
        $connections = config('database.connections');

        if (! is_array($connections)) {
            return;
        }

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || ! is_array($connection) || ($connection['driver'] ?? null) !== 'sqlite') {
                continue;
            }

            /** @var array<string, mixed> $connection */
            self::applyRuntimeDefaults($name, $connection);
        }
    }

    /**
     * Store the tuned runtime defaults for a configured SQLite connection.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function applyRuntimeDefaults(string $connection, array $config): array
    {
        $config = self::withRuntimeDefaults($config);

        config()->set("database.connections.{$connection}", $config);

        return $config;
    }

    /**
     * Merge SQLite runtime defaults into a connection configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function withRuntimeDefaults(array $config): array
    {
        $config['foreign_key_constraints'] ??= true;
        $config['busy_timeout'] ??= self::BUSY_TIMEOUT;
        $config['journal_mode'] ??= self::JOURNAL_MODE;
        $config['synchronous'] ??= self::SYNCHRONOUS;
        $config['pragmas'] = array_replace([
            'cache_size' => self::CACHE_SIZE,
            'mmap_size' => self::MMAP_SIZE,
            'temp_store' => self::TEMP_STORE,
        ], is_array($config['pragmas'] ?? null) ? $config['pragmas'] : []);

        return $config;
    }
}
