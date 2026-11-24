<?php

namespace Tests\Feature;

use App\Support\DatabaseSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the safeguards that keep the main "memberflow" database safe.
 * None of these tests ever connects to or modifies "memberflow".
 */
class DatabaseSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_against_the_dedicated_testing_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('memberflow_testing', DatabaseSafety::currentDatabase());
        $this->assertSame('memberflow_testing', DB::connection()->getDatabaseName());
    }

    public function test_reset_guard_refuses_the_main_database(): void
    {
        $original = config('database.connections.mysql.database');

        try {
            // Only the config value changes; the open connection still points at memberflow_testing.
            config(['database.connections.mysql.database' => 'memberflow']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to reset database [memberflow]');

            DatabaseSafety::assertSafeForTests();
        } finally {
            config(['database.connections.mysql.database' => $original]);
        }
    }

    public static function destructiveCommands(): array
    {
        return [
            'migrate:fresh' => ['migrate:fresh'],
            'migrate:refresh' => ['migrate:refresh'],
            'migrate:reset' => ['migrate:reset'],
            'migrate:rollback' => ['migrate:rollback'],
            'db:wipe' => ['db:wipe'],
        ];
    }

    #[DataProvider('destructiveCommands')]
    public function test_destructive_commands_are_prohibited_outside_the_testing_database(string $command): void
    {
        $original = config('database.connections.mysql.database');

        try {
            // Simulate the app pointing at a non-testing database (a name that does not exist),
            // then re-apply the same prohibition logic AppServiceProvider runs at boot.
            config(['database.connections.mysql.database' => 'memberflow_guard_probe']);
            DatabaseSafety::applyCommandProhibitions();

            $exitCode = Artisan::call($command, ['--force' => true]);

            $this->assertSame(1, $exitCode, "{$command} should be refused.");
            $this->assertStringContainsString('prohibited', Artisan::output());
        } finally {
            config(['database.connections.mysql.database' => $original]);
            DatabaseSafety::applyCommandProhibitions();
        }

        // The testing database is untouched: its migrations table is still there.
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('migrations'));
    }

    public function test_prohibition_is_lifted_only_for_the_testing_database(): void
    {
        $this->assertTrue(DatabaseSafety::isTestingDatabase('memberflow_testing'));
        $this->assertFalse(DatabaseSafety::isTestingDatabase('memberflow'));
        $this->assertFalse(DatabaseSafety::isTestingDatabase(''));
    }
}
