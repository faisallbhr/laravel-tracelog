<?php

namespace TraceLog\Listeners;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Throwable;
use TraceLog\Internal\TransactionTracker;

/** Bridges Laravel transaction events to the scoped transaction tracker. */
final class DatabaseTransactionListener
{
    public function __construct(private readonly TransactionTracker $tracker) {}

    public function beginning(TransactionBeginning $event): void
    {
        try {
            $this->tracker->begin($event->connection);
        } catch (Throwable) {
            // Transaction instrumentation must not change database behavior.
        }
    }

    public function committed(TransactionCommitted $event): void
    {
        try {
            $this->tracker->commit($event->connection);
        } catch (Throwable) {
            // Transaction instrumentation must not change database behavior.
        }
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        try {
            $this->tracker->rollback($event->connection);
        } catch (Throwable) {
            // Transaction instrumentation must not change database behavior.
        }
    }
}
