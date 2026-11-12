<?php

namespace TraceLog\Tests;

use Illuminate\Support\Facades\Route;

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

    public function test_viewer_routes_are_disabled_by_default(): void
    {
        $this->assertFalse(Route::has('tracelog.viewer'));
        $this->assertFalse(Route::has('tracelog.viewer.css'));
        $this->assertFalse(Route::has('tracelog.viewer.js'));
        $this->assertFalse(Route::has('tracelog.api.dates'));
    }
}
