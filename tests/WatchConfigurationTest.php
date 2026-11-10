<?php

namespace TraceLog\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TraceLog\Enums\ModelEvent;
use TraceLog\WatchConfiguration;

class WatchConfigurationTest extends TestCase
{
    public function test_only_and_except_filter_changes_in_order(): void
    {
        $configuration = (new WatchConfiguration)
            ->only(['total', 'status', 'notes'])
            ->except(['notes']);

        $changes = $configuration->filter([
            'status' => ['old' => 'pending', 'new' => 'paid'],
            'total' => ['old' => 100, 'new' => 150],
            'notes' => ['old' => null, 'new' => 'Paid'],
            'updated_at' => ['old' => 'before', 'new' => 'after'],
        ]);

        $this->assertSame(['status', 'total'], array_keys($changes));
    }

    public function test_empty_attribute_filters_have_explicit_meanings(): void
    {
        $changes = ['status' => ['old' => 'pending', 'new' => 'paid']];

        $this->assertSame([], (new WatchConfiguration)->only([])->filter($changes));
        $this->assertSame($changes, (new WatchConfiguration)->except([])->filter($changes));
    }

    public function test_sensitive_attributes_are_redacted_case_insensitively(): void
    {
        $changes = (new WatchConfiguration)
            ->only(['Password', 'pin', 'name'])
            ->sensitive(['PIN'])
            ->filter([
                'Password' => ['old' => 'old-secret', 'new' => 'new-secret'],
                'pin' => ['old' => '1234', 'new' => '5678'],
                'name' => ['old' => 'Jane', 'new' => 'John'],
            ]);

        $redacted = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];

        $this->assertSame($redacted, $changes['Password']);
        $this->assertSame($redacted, $changes['pin']);
        $this->assertSame(['old' => 'Jane', 'new' => 'John'], $changes['name']);
    }

    public function test_every_default_sensitive_attribute_is_redacted(): void
    {
        $attributes = [
            'password',
            'password_confirmation',
            'remember_token',
            'api_token',
            'access_token',
            'refresh_token',
            'secret',
            'secret_key',
            'private_key',
        ];
        $changes = [];

        foreach ($attributes as $attribute) {
            $changes[$attribute] = ['old' => 'old-secret', 'new' => 'new-secret'];
        }

        $filtered = (new WatchConfiguration)->filter($changes);

        foreach ($attributes as $attribute) {
            $this->assertSame(
                ['old' => '[REDACTED]', 'new' => '[REDACTED]'],
                $filtered[$attribute]
            );
        }
    }

    public function test_events_default_to_all_and_can_be_restricted(): void
    {
        $configuration = new WatchConfiguration;

        foreach (ModelEvent::cases() as $event) {
            $this->assertTrue($configuration->includes($event));
        }

        $configuration->events([ModelEvent::Updated]);

        $this->assertTrue($configuration->includes(ModelEvent::Updated));
        $this->assertFalse($configuration->includes(ModelEvent::Created));
        $this->assertFalse((new WatchConfiguration)->events([])->includes(ModelEvent::Updated));
    }

    public function test_invalid_event_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TraceLog events must be instances');

        (new WatchConfiguration)->events(['updated']);
    }

    public function test_invalid_attribute_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TraceLog attribute names must be strings');

        (new WatchConfiguration)->only(['status', 123]);
    }
}
