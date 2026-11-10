<?php

namespace TraceLog;

use InvalidArgumentException;
use TraceLog\Enums\ModelEvent;
use TraceLog\Internal\WatchRules;

/**
 * Configures filtering for one model watch registration.
 */
class WatchConfiguration
{
    private readonly WatchRules $rules;

    public function __construct(?WatchRules $rules = null)
    {
        $this->rules = $rules ?? new WatchRules;
    }

    /**
     * Restrict logged changes to the given attributes.
     *
     * @param  list<string>  $attributes
     */
    public function only(array $attributes): static
    {
        $this->rules->only($this->validateAttributes($attributes));

        return $this;
    }

    /**
     * Exclude the given attributes from logged changes.
     *
     * @param  list<string>  $attributes
     */
    public function except(array $attributes): static
    {
        $this->rules->except($this->validateAttributes($attributes));

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

        $this->rules->events(array_values($events));

        return $this;
    }

    /**
     * Add attributes whose old and new values must be redacted.
     *
     * @param  list<string>  $attributes
     */
    public function sensitive(array $attributes): static
    {
        $this->rules->sensitive($this->validateAttributes($attributes));

        return $this;
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
