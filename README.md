# Laravel Optimal SQLite

<!-- toc -->

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [What It Does](#what-it-does)
- [How This Differs From Nuno's Package](#how-this-differs-from-nunos-package)
- [Configuration](#configuration)
- [Existing Databases](#existing-databases)
- [Testing](#testing)
- [License](#license)

<!-- /toc -->

<a name="introduction"></a>

## Introduction

SQLite is a great fit for many Laravel applications, but its best production
settings need to be applied at the right time. Some settings belong to each
connection, while others affect the database file itself and must be applied
before Laravel creates schema tables.

Laravel Optimal SQLite gives your Laravel application production-oriented SQLite
defaults without adding a custom command to remember. When you run Laravel's
normal migration commands, the package prepares empty SQLite database files
before the migrations table is created. At runtime, it applies sensible SQLite
connection defaults to every configured SQLite connection.

This package was inspired by Nuno Maduro's
[`nunomaduro/laravel-optimize-database`](https://github.com/nunomaduro/laravel-optimize-database).
That package showed how useful a small SQLite optimization layer can be for
Laravel. Laravel Optimal SQLite takes the idea further by handling the lifecycle
issues around `page_size`, WAL mode, `VACUUM`, and `migrate:fresh`.

<a name="requirements"></a>

## Requirements

Laravel Optimal SQLite requires a Laravel application using a file-backed SQLite
connection. Runtime connection defaults may be applied to any SQLite connection,
but file-level settings such as `page_size` and `auto_vacuum` cannot be applied
to `:memory:` databases.

Laravel package discovery should be enabled so the service provider can register
automatically. If your application disables package discovery, manually register
the provider:

```php
Loadinglucian\LaravelOptimalSqlite\OptimalSqliteServiceProvider::class,
```

After installing the package, run your normal Laravel migrations. The package
optimizes empty SQLite database files during `php artisan migrate` and preserves
those file settings during `php artisan migrate:fresh`.

<a name="installation"></a>

## Installation

You may install the package with Composer:

```shell
composer require loadinglucian/laravel-optimal-sqlite
```

Laravel will discover the service provider automatically.

After installation, continue using Laravel's standard migration commands:

```shell
php artisan migrate
php artisan migrate:fresh
```

You do not need to run a package-specific optimization command.

<a name="what-it-does"></a>

## What It Does

The package applies two kinds of SQLite settings.

Runtime connection settings are applied to every configured SQLite connection
before Laravel opens it:

```text
PRAGMA busy_timeout = 5000
PRAGMA cache_size = -20000
PRAGMA foreign_keys = ON
PRAGMA mmap_size = 2147483648
PRAGMA temp_store = MEMORY
PRAGMA synchronous = NORMAL
PRAGMA journal_mode = WAL
```

File-level settings are applied automatically before migrations create tables:

```text
PRAGMA journal_mode = DELETE
PRAGMA auto_vacuum = INCREMENTAL
PRAGMA page_size = 32768
VACUUM
PRAGMA journal_mode = WAL
```

This sequence matters. SQLite can only apply `page_size` at database creation or
during `VACUUM` while the database is not in WAL mode. The package temporarily
leaves WAL mode, applies the file settings, vacuums the empty database file, and
then restores WAL mode.

The package also replaces Laravel's SQLite `db:wipe` behavior for file-backed
SQLite databases. Laravel normally empties the SQLite file during `db:wipe`,
which resets file-level settings such as `page_size`. Laravel Optimal SQLite
drops schema objects instead, so repeated `migrate:fresh` runs keep the
optimized file format intact.

<a name="how-this-differs-from-nunos-package"></a>

## How This Differs From Nuno's Package

Nuno's package provided the useful starting point: codify a production-ready
SQLite profile for Laravel. Laravel Optimal SQLite keeps that spirit, but it
changes the delivery mechanism.

This package does not publish a migration stub. Laravel creates the `migrations`
table before running the first migration, so a "first" migration is still too
late for some SQLite file settings unless it performs a careful `VACUUM` flow.
Instead, this package hooks into Laravel's normal `migrate` command and applies
file-level settings before the migrations table exists.

This package also avoids an extra Artisan command in your day-to-day workflow.
You continue running `php artisan migrate` and `php artisan migrate:fresh`.

Finally, this package tests the observed SQLite values after tuning. The test
suite verifies the real `PRAGMA` values, including `page_size`, `auto_vacuum`,
WAL mode, runtime connection settings, and repeated `migrate:fresh` behavior.

<a name="configuration"></a>

## Configuration

By default, every configured SQLite connection receives the package defaults.
You may override runtime settings directly in your `config/database.php`
connection array:

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'busy_timeout' => 10000,
    'journal_mode' => 'WAL',
    'synchronous' => 'NORMAL',
    'pragmas' => [
        'cache_size' => -40000,
        'mmap_size' => 2147483648,
        'temp_store' => 'MEMORY',
    ],
],
```

The file-level defaults are intentionally not exposed through environment
variables. They affect the database file format and should remain stable across
environments.

<a name="existing-databases"></a>

## Existing Databases

The automatic migration flow only optimizes SQLite files that do not yet have
user-created tables. Existing populated databases are skipped so the package
does not unexpectedly rewrite a production database file.

Before applying file-level changes to an existing SQLite database, take a
backup. Then perform the maintenance explicitly in your own deployment process
or call the `SQLiteDatabaseOptimizer` service from a controlled script.

<a name="testing"></a>

## Testing

You may run the package test suite with Composer:

```shell
composer test
```

The tests use real temporary SQLite files and assert the actual post-tuning
`PRAGMA` values.

<a name="license"></a>

## License

Laravel Optimal SQLite is open-sourced software licensed under the
[MIT license](LICENSE.md).
