<?php

namespace TraceLog\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class TraceLogViewerTest extends TestCase
{
    private string $storage_path;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('tracelog.viewer.enabled', true);
        $app['config']->set('tracelog.viewer.middleware', []);
        $app['config']->set('tracelog.reader.page_size', 1);
        $app['config']->set('tracelog.reader.max_page_size', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewTraceLog', fn (?Authenticatable $user = null): bool => true);

        $this->storage_path = sys_get_temp_dir().'/tracelog-viewer-'.uniqid('', true);
        mkdir($this->storage_path.'/logs', 0777, true);
        $this->app->useStoragePath($this->storage_path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage_path.'/logs/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->storage_path.'/logs');
        rmdir($this->storage_path);

        parent::tearDown();
    }

    public function test_authorized_user_can_load_viewer_page_and_dates(): void
    {
        $this->write('tracelog-2026-11-11-1.log', '');
        $this->get('/tracelog')
            ->assertOk()
            ->assertSee('TraceLog Viewer')
            ->assertSee('/tracelog/assets/viewer.css')
            ->assertSee('/tracelog/assets/viewer.js')
            ->assertSee('data-page-size="1"', false);
        $this->get('/tracelog/assets/viewer.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get('/tracelog/assets/viewer.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $this->getJson('/tracelog/api/dates')
            ->assertOk()
            ->assertJson(['dates' => ['2026-11-11']]);
    }

    public function test_viewer_routes_are_registered_only_when_enabled(): void
    {
        $this->assertTrue(Route::has('tracelog.viewer'));
        $this->assertTrue(Route::has('tracelog.viewer.css'));
        $this->assertTrue(Route::has('tracelog.viewer.js'));
        $this->assertTrue(Route::has('tracelog.api.dates'));
    }

    public function test_unauthorized_requests_are_rejected(): void
    {
        Gate::define('viewTraceLog', fn (?Authenticatable $user = null): bool => false);

        $this->get('/tracelog')->assertForbidden();
        $this->getJson('/tracelog/api/dates')->assertForbidden();
    }

    public function test_trace_list_and_detail_are_date_scoped_and_paginated(): void
    {
        $this->write('tracelog-2026-11-11-1.log', implode("\n", [
            $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', ['checkout'], 'Order'),
            $this->event('ccccdddd', '2026-11-11T00:00:01.000000+00:00', ['other'], 'Stock'),
        ])."\n");
        $this->write('tracelog-2026-11-11-2.log', $this->event('aaaabbbb', '2026-11-11T00:00:02.000000+00:00', ['checkout'], 'Stock')."\n");

        $this->getJson('/tracelog/api/traces?date=2026-11-11&per_page=1&tag=checkout')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('traces.0.trace_id', 'aaaabbbb')
            ->assertJsonPath('traces.0.event_count', 2);

        $this->getJson('/tracelog/api/traces/aaaabbbb?date=2026-11-11')
            ->assertOk()
            ->assertJsonCount(2, 'events');

        $this->getJson('/tracelog/api/traces/aaaabbbb?date=2026-11-12')->assertNotFound();
    }

    public function test_trace_list_uses_configured_page_size_when_omitted(): void
    {
        $this->write('tracelog-2026-11-11-1.log', $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', [], 'Order')."\n");

        $this->getJson('/tracelog/api/traces?date=2026-11-11')
            ->assertOk()
            ->assertJsonPath('per_page', 1);
    }

    public function test_trace_list_explains_when_page_size_exceeds_maximum(): void
    {
        $this->getJson('/tracelog/api/traces?date=2026-11-11&per_page=2')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Invalid trace query: per_page cannot exceed max_page_size (1).');
    }

    public function test_invalid_queries_return_bad_request_without_paths(): void
    {
        $response = $this->getJson('/tracelog/api/traces?date=not-a-date&page=nope');

        $response->assertBadRequest();
        $this->assertStringNotContainsString($this->storage_path, $response->getContent());
    }

    private function write(string $filename, string $contents): void
    {
        file_put_contents($this->storage_path.'/logs/'.$filename, $contents);
    }

    /** @param list<string> $tags */
    private function event(string $trace_id, string $timestamp, array $tags, string $model): string
    {
        return json_encode([
            'timestamp' => $timestamp,
            'trace_id' => $trace_id,
            'tags' => $tags,
            'event' => 'updated',
            'model' => $model,
            'id' => 1,
            'source' => '/app/Order.php:10',
            'changes' => [
                'status' => ['old' => null, 'new' => ['nested' => true]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
