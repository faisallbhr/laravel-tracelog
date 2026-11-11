<?php

namespace TraceLog\Tests;

use InvalidArgumentException;
use TraceLog\Exceptions\TraceFileNotFoundException;
use TraceLog\Exceptions\TraceFileUnreadableException;
use TraceLog\Internal\TraceReader;
use TraceLog\Internal\TraceWriter;

class TraceReaderTest extends TestCase
{
    private string $storage_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage_path = sys_get_temp_dir().'/tracelog-reader-'.uniqid('', true);
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

    public function test_files_are_discovered_newest_first_and_invalid_names_are_ignored(): void
    {
        $this->write('tracelog-2026-11-09-1.log', '');
        $this->write('tracelog-2026-11-11-1.log', '');
        $this->write('tracelog-2026-02-30-1.log', '');
        $this->write('other.log', '');

        $this->assertSame([
            'tracelog-2026-11-11-1.log',
            'tracelog-2026-11-09-1.log',
        ], $this->reader()->files());
    }

    public function test_traces_group_interleaved_events_and_paginate_by_trace(): void
    {
        $a = $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', ['checkout'], 'Order');
        $b = $this->event('ccccdddd', '2026-11-11T00:00:01.000000+00:00', ['inventory'], 'Stock');
        $a2 = $this->event('aaaabbbb', '2026-11-11T00:00:02.000000+00:00', ['inventory'], 'Stock');

        $this->write('tracelog-2026-11-11-1.log', implode("\n", [$a, $b, $a2])."\n");

        $result = $this->reader()->traces('tracelog-2026-11-11-1.log', 1, 1);

        $this->assertSame(['page' => 1, 'per_page' => 1, 'total' => 2, 'has_more' => true], array_intersect_key($result, array_flip(['page', 'per_page', 'total', 'has_more'])));
        $this->assertSame('aaaabbbb', $result['traces'][0]['trace_id']);
        $this->assertSame(['checkout', 'inventory'], $result['traces'][0]['tags']);
        $this->assertSame(['Order', 'Stock'], $result['traces'][0]['models']);
        $this->assertSame(2, $result['traces'][0]['event_count']);
    }

    public function test_trace_keeps_event_order_across_parts_but_not_days(): void
    {
        $first = $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', [], 'Order');
        $second = $this->event('aaaabbbb', '2026-11-11T00:00:01.000000+00:00', [], 'Order');
        $this->write('tracelog-2026-11-11-1.log', "$first\n$second\n");
        $this->write('tracelog-2026-11-11-2.log', $this->event('aaaabbbb', '2026-11-11T00:00:02.000000+00:00', [], 'Stock')."\n");
        $this->write('tracelog-2026-11-12-1.log', $this->event('aaaabbbb', '2026-11-12T00:00:00.000000+00:00', [], 'Invoice')."\n");

        $trace = $this->reader()->trace('tracelog-2026-11-11-1.log', 'aaaabbbb');

        $this->assertSame(3, $trace['event_count']);
        $this->assertSame(['Order', 'Order', 'Stock'], array_column($trace['events'], 'model'));
        $this->assertSame('2026-11-11T00:00:00.000000+00:00', $trace['started_at']);
        $this->assertSame('2026-11-11T00:00:02.000000+00:00', $trace['ended_at']);
        $this->assertSame(1, $this->reader()->trace('tracelog-2026-11-12-1.log', 'aaaabbbb')['event_count']);
    }

    public function test_traces_for_date_groups_each_trace_once_across_parts(): void
    {
        $this->write('tracelog-2026-11-11-1.log', implode("\n", [
            $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', ['checkout'], 'Order'),
            $this->event('ccccdddd', '2026-11-11T00:00:01.000000+00:00', [], 'Stock'),
        ])."\n");
        $this->write('tracelog-2026-11-11-2.log', $this->event('aaaabbbb', '2026-11-11T00:00:02.000000+00:00', ['inventory'], 'Stock')."\n");

        $result = $this->reader()->tracesForDate('2026-11-11');
        $trace = array_values(array_filter($result['traces'], fn (array $summary): bool => $summary['trace_id'] === 'aaaabbbb'))[0];

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $trace['event_count']);
        $this->assertSame([
            'tracelog-2026-11-11-1.log',
            'tracelog-2026-11-11-2.log',
        ], $trace['files']);
        $this->assertSame(['checkout', 'inventory'], $trace['tags']);
    }

    public function test_summary_uses_minimum_and_maximum_event_timestamps(): void
    {
        $this->write('tracelog-2026-11-11-1.log', implode("\n", [
            $this->event('aaaabbbb', '2026-11-11T00:00:02.000000+00:00', [], 'Order'),
            $this->event('aaaabbbb', '2026-11-11T00:00:01.000000+00:00', [], 'Order'),
        ])."\n");

        $trace = $this->reader()->trace('tracelog-2026-11-11-1.log', 'aaaabbbb');

        $this->assertSame('2026-11-11T00:00:01.000000+00:00', $trace['started_at']);
        $this->assertSame('2026-11-11T00:00:02.000000+00:00', $trace['ended_at']);
    }

    public function test_tag_filter_is_exact_and_no_match_returns_empty_page(): void
    {
        $this->write('tracelog-2026-11-11-1.log', implode("\n", [
            $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', ['Order'], 'Order'),
            $this->event('ccccdddd', '2026-11-11T00:00:01.000000+00:00', ['order'], 'Order'),
        ])."\n");

        $result = $this->reader()->traces('tracelog-2026-11-11-1.log', tag: 'Order');

        $this->assertSame(1, $result['total']);
        $this->assertSame('aaaabbbb', $result['traces'][0]['trace_id']);
        $this->assertSame(0, $this->reader()->traces('tracelog-2026-11-11-1.log', tag: 'missing')['total']);
    }

    public function test_reader_rejects_invalid_page(): void
    {
        $this->write('tracelog-2026-11-11-1.log', '');

        $this->expectException(InvalidArgumentException::class);
        $this->reader()->traces('tracelog-2026-11-11-1.log', 0);
    }

    public function test_reader_rejects_per_page_above_configured_limit(): void
    {
        config(['tracelog.reader.max_page_size' => 1]);
        $this->write('tracelog-2026-11-11-1.log', '');

        $this->expectException(InvalidArgumentException::class);
        $this->reader()->traces('tracelog-2026-11-11-1.log', per_page: 2);
    }

    public function test_reader_rejects_invalid_trace_id(): void
    {
        $this->write('tracelog-2026-11-11-1.log', '');

        $this->expectException(InvalidArgumentException::class);
        $this->reader()->trace('tracelog-2026-11-11-1.log', 'not-valid');
    }

    public function test_malformed_records_and_incomplete_final_line_are_skipped(): void
    {
        $valid = $this->event('aaaabbbb', '2026-11-11T00:00:00.000000+00:00', [], 'Order');
        $invalid = json_encode(['trace_id' => 'bad']);

        $this->write('tracelog-2026-11-11-1.log', "$valid\nnot-json\n$invalid\n{");

        $result = $this->reader()->traces('tracelog-2026-11-11-1.log');

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['traces'][0]['event_count']);
    }

    public function test_invalid_filename_traversal_missing_file_and_symlink_are_rejected(): void
    {
        $reader = $this->reader();

        $this->expectException(InvalidArgumentException::class);
        $reader->trace('../tracelog-2026-11-11-1.log', 'aaaabbbb');
    }

    public function test_missing_file_has_a_distinct_failure(): void
    {
        $this->expectException(TraceFileNotFoundException::class);
        $this->reader()->traces('tracelog-2026-11-11-1.log');
    }

    public function test_symlink_has_a_distinct_failure(): void
    {
        $target = $this->storage_path.'/logs/target.log';
        file_put_contents($target, '');
        symlink($target, $this->storage_path.'/logs/tracelog-2026-11-11-1.log');

        $this->expectException(TraceFileUnreadableException::class);
        $this->reader()->traces('tracelog-2026-11-11-1.log');
    }

    public function test_existing_trace_writer_output_is_read_without_conversion(): void
    {
        $this->app->make(TraceWriter::class)->write([
            'trace_id' => 'aaaabbbb',
            'tags' => ['checkout'],
            'event' => 'updated',
            'model' => 'App\\Models\\Order',
            'id' => 123,
            'changes' => [],
            'source' => null,
        ]);

        $trace = $this->reader()->trace('tracelog-'.now()->format('Y-m-d').'-1.log', 'aaaabbbb');

        $this->assertNotNull($trace);
        $this->assertSame('updated', $trace['events'][0]['event']);
        $this->assertSame(['checkout'], $trace['tags']);
    }

    private function reader(): TraceReader
    {
        return $this->app->make(TraceReader::class);
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
            'source' => null,
            'changes' => [],
        ], JSON_THROW_ON_ERROR);
    }
}
