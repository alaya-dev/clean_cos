<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! $this->app->environment('testing') || ! Str::endsWith($database, '_test')) {
            throw new RuntimeException('Les tests doivent utiliser une base de données isolée dont le nom se termine par _test.');
        }
    }
}
