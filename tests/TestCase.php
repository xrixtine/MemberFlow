<?php

namespace Tests;

use App\Support\DatabaseSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Database safety guard.
     *
     * Laravel calls setUpTraits() after the application boots and BEFORE any
     * database trait runs (RefreshDatabase, DatabaseMigrations,
     * DatabaseTruncation). If the active connection is not the dedicated
     * "memberflow_testing" database, the test aborts before anything is
     * migrated or wiped, so the main "memberflow" database is never touched.
     *
     * (A beforeRefreshingDatabase() override here would NOT work: the empty
     * method inside the RefreshDatabase trait would silently replace it.)
     */
    protected function setUpTraits()
    {
        DatabaseSafety::assertSafeForTests();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Blade views use @vite; tests do not need built assets.
        $this->withoutVite();
    }
}
