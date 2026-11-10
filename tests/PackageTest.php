<?php

namespace TraceLog\Tests;

class PackageTest extends TestCase
{
    public function test_laravel_application_boots(): void
    {
        $this->assertNotNull($this->app);
    }

    public function test_laravel_container_is_available(): void
    {
        $this->assertTrue($this->app->bound('config'));
    }
}
