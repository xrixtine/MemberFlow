<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guards that keep destructive operations away from the main MemberFlow database.
 */
final class DatabaseSafety
{
    public static function testingDatabase(): string
    {
        return (string) config('memberflow.testing_database', 'memberflow_testing');
    }

    public static function currentDatabase(): string
    {
        $connection = config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    public static function isTestingDatabase(?string $database = null): bool
    {
        return ($database ?? self::currentDatabase()) === self::testingDatabase();
    }

    /**
     * Block migrate:fresh / refresh / reset / rollback and db:wipe unless the
     * active connection points at the dedicated testing database.
     */
    public static function applyCommandProhibitions(): void
    {
        DB::prohibitDestructiveCommands(! self::isTestingDatabase());
    }

    /**
     * Called by the test suite before RefreshDatabase migrates/wipes anything.
     *
     * @throws RuntimeException when tests are not pointed at the testing database
     */
    public static function assertSafeForTests(): void
    {
        $database = self::currentDatabase();

        if (! app()->environment('testing') || ! self::isTestingDatabase($database)) {
            throw new RuntimeException(sprintf(
                'Refusing to reset database [%s] in environment [%s]. Tests may only use [%s]. '
                .'Run "php artisan config:clear" and check phpunit.xml / .env.testing.',
                $database,
                app()->environment(),
                self::testingDatabase(),
            ));
        }
    }
}
