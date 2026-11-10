<?php

namespace TraceLog;

use InvalidArgumentException;
use TraceLog\Enums\ModelEvent;

/**
 * Configures filtering for one model watch registration.
 */
class WatchConfiguration
{
    private const SENSITIVE_ATTRIBUTES = [
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

    /** @var list<string>|null */
    private ?array $only = null;

    /** @var list<string> */
    private array $except = [];

    /** @var list<ModelEvent>|null */
    private ?array $events = null;

    /** @var list<string> */
    private array $sensitive = [];

    /**
     * Restrict logged changes to the given attributes.
     *
     * @param  list<string>  $attributes
     */
    public function only(array $attributes): static
    {
        $this->only = $this->validateAttributes($attributes);

        return $this;
    }

    /**
     * Exclude the given attributes from logged changes.
     *
     * @param  list<string>  $attributes
     */
    public function except(array $attributes): static
    {
        $this->except = $this->validateAttributes($attributes);

        return $this;
    }

    /**
     * Restrict logging to the given model events.
     *
     * @param  list<ModelEvent>  $events
     */
    public function events(array $events): static
    {
        foreach ($events as $event) {
            if (! $event instanceof ModelEvent) {
                throw new InvalidArgumentException('TraceLog events must be instances of '.ModelEvent::class.'.');
            }
        }

        $this->events = array_values($events);

        return $this;
    }

    /**
     * Add attributes whose old and new values must be redacted.
     *
     * @param  list<string>  $attributes
     */
    public function sensitive(array $attributes): static
    {
        $this->sensitive = $this->validateAttributes($attributes);

        return $this;
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
        $sensitive = array_map('strtolower', [...self::SENSITIVE_ATTRIBUTES, ...$this->sensitive]);

        foreach (array_keys($changes) as $attribute) {
            if (in_array(strtolower($attribute), $sensitive, true)) {
                $changes[$attribute] = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];
            }
        }

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return list<string>
     */
    private function validateAttributes(array $attributes): array
    {
        foreach ($attributes as $attribute) {
            if (! is_string($attribute)) {
                throw new InvalidArgumentException('TraceLog attribute names must be strings.');
            }
        }

        return array_values($attributes);
    }
}
