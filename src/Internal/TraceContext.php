<?php

namespace TraceLog\Internal;

use InvalidArgumentException;

/** Owns trace metadata for the current application scope. */
final class TraceContext
{
    private ?string $trace_id = null;

    /** @var list<string> */
    private array $tags = [];

    /** @param list<string> $tags */
    public function addTags(array $tags): void
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                throw new InvalidArgumentException('TraceLog tags must be strings.');
            }

            $tag = trim($tag);

            if ($tag !== '' && ! in_array($tag, $this->tags, true) && ! in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        $this->tags = [...$this->tags, ...$normalized];
    }

    /** @return array{trace_id: string, tags: list<string>} */
    public function metadata(): array
    {
        return [
            'trace_id' => $this->trace_id ??= bin2hex(random_bytes(4)),
            'tags' => $this->tags,
        ];
    }

    public function reset(): void
    {
        $this->trace_id = null;
        $this->tags = [];
    }
}
