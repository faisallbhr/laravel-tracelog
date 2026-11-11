<?php

namespace TraceLog\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TraceLog\Enums\ModelEvent;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\WatchRules;
use TraceLog\WatchConfiguration;

class WatchConfigurationTest extends TestCase
{
    public function test_only_and_except_filter_changes_in_order(): void
    {
        $rules = new WatchRules;

        (new WatchConfiguration($rules))
            ->only(['total', 'status', 'notes'])
            ->except(['notes']);

        $changes = $rules->filter([
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
        $only_rules = new WatchRules;
        $except_rules = new WatchRules;

        (new WatchConfiguration($only_rules))->only([]);
        (new WatchConfiguration($except_rules))->except([]);

        $this->assertSame([], $only_rules->filter($changes));
        $this->assertSame($changes, $except_rules->filter($changes));
    }

    public function test_sensitive_attributes_are_redacted_case_insensitively(): void
    {
        $rules = new WatchRules;

        (new WatchConfiguration($rules))
            ->only(['Password', 'pin', 'name'])
            ->sensitive(['PIN']);

        $changes = $rules->filter([
            'Password' => ['old' => 'old-secret', 'new' => 'new-secret'],
            'pin' => ['old' => '1234', 'new' => '5678'],
            'name' => ['old' => 'Jane', 'new' => 'John'],
        ]);

        $redacted = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];

        $this->assertSame($redacted, $changes['Password']);
        $this->assertSame($redacted, $changes['pin']);
        $this->assertSame(['old' => 'Jane', 'new' => 'John'], $changes['name']);
    }

    public function test_repeated_sensitive_calls_keep_previous_attributes(): void
    {
        $rules = new WatchRules;

        (new WatchConfiguration($rules))
            ->sensitive(['pin'])
            ->sensitive(['security_answer']);

        $changes = $rules->filter([
            'pin' => ['old' => '1234', 'new' => '5678'],
            'security_answer' => ['old' => 'Blue', 'new' => 'Green'],
        ]);

        $redacted = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];

        $this->assertSame($redacted, $changes['pin']);
        $this->assertSame($redacted, $changes['security_answer']);
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

        $filtered = (new WatchRules)->filter($changes);

        foreach ($attributes as $attribute) {
            $this->assertSame(
                ['old' => '[REDACTED]', 'new' => '[REDACTED]'],
                $filtered[$attribute]
            );
        }
    }

    public function test_events_default_to_all_and_can_be_restricted(): void
    {
        $rules = new WatchRules;
        $configuration = new WatchConfiguration($rules);

        foreach (ModelEvent::cases() as $event) {
            $this->assertTrue($rules->includes($event));
        }

        $configuration->events([ModelEvent::Updated]);

        $this->assertTrue($rules->includes(ModelEvent::Updated));
        $this->assertFalse($rules->includes(ModelEvent::Created));

        $empty_rules = new WatchRules;
        (new WatchConfiguration($empty_rules))->events([]);

        $this->assertFalse($empty_rules->includes(ModelEvent::Updated));
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

    public function test_tags_are_normalized_and_chainable(): void
    {
        $context = new TraceContext;

        $configuration = new WatchConfiguration(new WatchRules, $context);

        $this->assertSame($configuration, $configuration->tags([' checkout ', '', 'checkout', 'Inventory'])->tags(['inventory', 'sales']));
        $this->assertSame(['checkout', 'Inventory', 'inventory', 'sales'], $context->metadata()['tags']);
    }

    public function test_invalid_tag_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TraceLog tags must be strings');

        (new WatchConfiguration)->tags(['checkout', 123]);
    }
}
