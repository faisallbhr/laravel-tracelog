<?php

namespace TraceLog\Internal;

use TraceLog\Enums\ModelEvent;

/** Stores and evaluates the rules behind one watch registration. */
final class WatchRules
{
    /** @param list<string> $default_sensitive_attributes */
    public function __construct(
        private readonly array $default_sensitive_attributes = []
    ) {}

    /** @var list<string>|null */
    private ?array $only = null;

    /** @var list<string> */
    private array $except = [];

    /** @var list<ModelEvent>|null */
    private ?array $events = null;

    /** @var list<string> */
    private array $sensitive = [];

    /** @return array{only: list<string>|null, except: list<string>, events: list<string>|null, sensitive: list<string>} */
    public function snapshot(): array
    {
        return [
            'only' => $this->only,
            'except' => $this->except,
            'events' => $this->events === null
                ? null
                : array_map(static fn (ModelEvent $event): string => $event->value, $this->events),
            'sensitive' => $this->sensitive,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(array $snapshot): void
    {
        $this->only = $snapshot['only'] ?? null;
        $this->except = $snapshot['except'] ?? [];
        $this->events = isset($snapshot['events'])
            ? array_map(static fn (string $event): ModelEvent => ModelEvent::from($event), $snapshot['events'])
            : null;
        $this->sensitive = $snapshot['sensitive'] ?? [];
    }

    /** @param list<string> $attributes */
    public function only(array $attributes): void
    {
        $this->only = $attributes;
    }

    /** @param list<string> $attributes */
    public function except(array $attributes): void
    {
        $this->except = $attributes;
    }

    /** @param list<ModelEvent> $events */
    public function events(array $events): void
    {
        $this->events = $events;
    }

    /** @param list<string> $attributes */
    public function sensitive(array $attributes): void
    {
        $this->sensitive = [...$this->sensitive, ...$attributes];
    }

    public function includes(ModelEvent $event): bool
    {
        return $this->events === null || in_array($event, $this->events, true);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function filter(array $changes): array
    {
        if ($this->only !== null) {
            $changes = array_intersect_key($changes, array_flip($this->only));
        }

        $changes = array_diff_key($changes, array_flip($this->except));
        $sensitive = array_map('strtolower', [...$this->default_sensitive_attributes, ...$this->sensitive]);

        foreach (array_keys($changes) as $attribute) {
            if (in_array(strtolower($attribute), $sensitive, true)) {
                $changes[$attribute] = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];
            }
        }

        return $changes;
    }
}
