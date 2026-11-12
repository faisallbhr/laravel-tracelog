<?php

namespace TraceLog\Tests;

use Illuminate\Support\Facades\Artisan;
use TraceLog\TraceLogServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertNotNull(app()->getProvider(TraceLogServiceProvider::class));
    }

    public function test_install_command_is_registered(): void
    {
        $this->assertArrayHasKey('tracelog:install', Artisan::all());
    }
}
