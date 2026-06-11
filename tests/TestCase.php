<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = env('DB_DATABASE');
        if ($database === ':memory:' || $database === null || $database === '') {
            $database = 'stuckbema';
        }

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.search_path' => 'testing',
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::statement('create schema if not exists testing');
        DB::statement('set search_path to testing');
    }
}
