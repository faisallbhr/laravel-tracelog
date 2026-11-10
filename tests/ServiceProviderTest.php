<?php

namespace TraceLog\Tests;

use TraceLog\TraceLogServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertNotNull($this->app->getProvider(TraceLogServiceProvider::class));
    }
}
