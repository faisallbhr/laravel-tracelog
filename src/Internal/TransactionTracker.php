<?php

namespace TraceLog\Internal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Tracks observed Laravel transaction frames for the current scope. */
final class TransactionTracker
{
    /** @var array<int, array{name: string, transaction_id: string, frames: array<int, string>, begins: list<array{level: int, frame_id: string}>, partial: bool, observed: bool}> */
    private array $connections = [];

    public function __construct(
        private readonly WatchRegistry $registry,
        private readonly TraceContext $context,
        private readonly TraceWriter $writer,
    ) {}

    public function begin(Connection $connection): void
    {
        if (! $this->registry->hasActiveWatch()) {
            return;
        }

        $level = $connection->transactionLevel();
        $key = $this->key($connection);
        $state = $this->connections[$key] ?? null;

        if ($level === 1 || $state === null) {
            $state = [
                'name' => $connection->getName(),
                'transaction_id' => 'tx-'.bin2hex(random_bytes(6)),
                'frames' => [],
                'begins' => [],
                'partial' => false,
                'observed' => false,
            ];
        }

        if ($level > 1 && ! $connection->getQueryGrammar()->supportsSavepoints()) {
            $state['partial'] = true;
        }

        $frame_id = 'frame-'.bin2hex(random_bytes(6));
        $state['frames'][$level] = $frame_id;
        $state['begins'][] = ['level' => $level, 'frame_id' => $frame_id];
        $this->connections[$key] = $state;
    }

    public function commit(Connection $connection): void
    {
        $this->lifecycleEvent($connection, 'commit');
    }

    public function rollback(Connection $connection): void
    {
        $this->lifecycleEvent($connection, 'rollback');
    }

    /** @return array<string, mixed>|null */
    public function metadataFor(Model $model): ?array
    {
        if (! $this->registry->hasActiveWatch()) {
            return null;
        }

        $connection = $model->getConnection();
        $level = $connection->transactionLevel();
        $key = $this->key($connection);

        if ($level < 1) {
            return [
                'id' => null,
                'connection_key' => $key,
                'connection_name' => $connection->getName(),
                'level' => 0,
                'frame_id' => null,
            ];
        }

        $state = $this->connections[$key] ?? [
            'name' => $connection->getName(),
            'transaction_id' => 'tx-'.bin2hex(random_bytes(6)),
            'frames' => [],
            'begins' => [],
            'partial' => true,
            'observed' => false,
        ];
        $state['frames'][$level] ??= 'frame-'.bin2hex(random_bytes(6));
        $state['observed'] = true;
        $this->connections[$key] = $state;
        $this->flushBegins($connection, $state);
        $this->connections[$key] = $state;

        return [
            'id' => $state['transaction_id'],
            'connection_key' => $key,
            'connection_name' => $state['name'],
            'level' => $level,
            'frame_id' => $state['frames'][$level],
            'partial' => $state['partial'],
        ];
    }

    public function reset(): void
    {
        $this->connections = [];
    }

    private function lifecycleEvent(Connection $connection, string $action): void
    {
        if (! $this->registry->hasActiveWatch()) {
            return;
        }

        $key = $this->key($connection);
        $state = $this->connections[$key] ?? null;
        $to_level = $connection->transactionLevel();
        $from_level = $to_level + 1;

        if ($state === null) {
            return;
        }

        if (! $state['observed']) {
            unset($this->connections[$key]);

            return;
        }

        $this->flushBegins($connection, $state);

        $frame_id = $state['frames'][$from_level] ?? null;
        $this->write($this->lifecycle($connection, $state, $frame_id, $action, $from_level, $to_level));

        if ($to_level === 0) {
            unset($this->connections[$key]);

            return;
        }

        foreach (array_keys($state['frames']) as $level) {
            if ($level >= $from_level) {
                unset($state['frames'][$level]);
            }
        }
        $this->connections[$key] = $state;
    }

    /** @param array<string, mixed> $state */
    private function flushBegins(Connection $connection, array &$state): void
    {
        foreach ($state['begins'] as $begin) {
            $this->write($this->lifecycle(
                $connection,
                $state,
                $begin['frame_id'],
                'begin',
                $begin['level'] - 1,
                $begin['level'],
            ));
        }

        $state['begins'] = [];
    }

    /** @return array<string, mixed> */
    private function lifecycle(Connection $connection, array $state, ?string $frame_id, string $action, int $from_level, int $to_level): array
    {
        return [
            ...$this->context->metadata(),
            'event' => 'transaction',
            'source' => null,
            'transaction' => [
                'id' => $state['transaction_id'],
                'connection_key' => $this->key($connection),
                'connection_name' => $connection->getName(),
                'action' => $action,
                'from_level' => $from_level,
                'to_level' => $to_level,
                'level' => $from_level,
                'frame_id' => $frame_id,
                'partial' => $state['partial'],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): void
    {
        try {
            $this->writer->write($payload);
        } catch (Throwable) {
            // Transaction tracing must never alter database behavior.
        }
    }

    private function key(Connection $connection): string
    {
        return 'conn-'.spl_object_id($connection);
    }
}
