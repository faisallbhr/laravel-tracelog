<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use TraceLog\Facades\TraceLog;
use TraceLog\Internal\TraceReader;

class TransactionAwarenessTest extends TestCase
{
    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log_path = storage_path('logs/tracelog-'.now()->format('Y-m-d').'-1.log');
        $this->clearLogs();
        Schema::create('transaction_test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        TraceLog::stop();
        Schema::dropIfExists('transaction_test_models');
        $this->clearLogs();
        parent::tearDown();
    }

    public function test_commit_marks_model_event_committed(): void
    {
        TraceLog::watch();
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'committed']);
        DB::commit();

        $event = $this->trace()['events'][1];
        $this->assertSame('created', $event['event']);
        $this->assertSame('committed', $event['transaction_status']);
        $this->assertSame('committed', TransactionTestModel::query()->value('name'));
        $this->assertCount(1, array_filter(
            $this->trace()['events'],
            static fn (array $event): bool => $event['event'] === 'transaction' && $event['transaction']['action'] === 'begin'
        ));
    }

    public function test_rollback_marks_model_event_rolled_back(): void
    {
        TraceLog::watch();
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'rolled back']);
        DB::rollBack();

        $event = $this->trace()['events'][1];
        $this->assertSame('rolled_back', $event['transaction_status']);
        $this->assertNull(TransactionTestModel::query()->value('name'));
    }

    public function test_event_outside_transaction_is_not_claimed_committed(): void
    {
        TraceLog::watch();
        TransactionTestModel::create(['name' => 'outside']);

        $event = $this->trace()['events'][0];
        $this->assertSame('outside', $event['transaction_status']);
    }

    public function test_unobserved_transaction_does_not_create_trace_noise(): void
    {
        TraceLog::watch();
        DB::beginTransaction();
        DB::commit();

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_nested_rollback_only_marks_inner_event_rolled_back(): void
    {
        TraceLog::watch();
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'outer']);
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'inner']);
        DB::rollBack();
        DB::commit();

        $events = array_values(array_filter(
            $this->trace()['events'],
            static fn (array $event): bool => $event['event'] === 'created'
        ));

        $this->assertSame(['committed', 'rolled_back'], array_column($events, 'transaction_status'));
        $this->assertSame(['outer'], TransactionTestModel::query()->pluck('name')->all());
    }

    public function test_callback_transaction_commit_marks_event_committed(): void
    {
        TraceLog::watch();

        DB::transaction(static function (): void {
            TransactionTestModel::create(['name' => 'callback committed']);
        });

        $events = array_values(array_filter(
            $this->trace()['events'],
            static fn (array $event): bool => $event['event'] === 'created'
        ));
        $this->assertSame('committed', $events[0]['transaction_status']);
    }

    public function test_callback_transaction_rollback_is_detected_without_exception_event(): void
    {
        TraceLog::watch();

        try {
            DB::transaction(static function (): void {
                TransactionTestModel::create(['name' => 'callback rolled back']);
                throw new RuntimeException('rollback without report');
            });
        } catch (RuntimeException) {
            // The application may handle the exception without reporting it.
        }

        $events = $this->trace()['events'];
        $model_events = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'created'));
        $this->assertSame('rolled_back', $model_events[0]['transaction_status']);
        $this->assertCount(0, array_filter($events, static fn (array $event): bool => $event['event'] === 'exception'));
    }

    public function test_reported_exception_does_not_change_later_rollback_outcome(): void
    {
        TraceLog::watch();
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'reported then rolled back']);
        report(new RuntimeException('business failure'));
        DB::rollBack();

        $events = $this->trace()['events'];
        $model = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'created'))[0];
        $this->assertSame('rolled_back', $model['transaction_status']);
        $this->assertSame(['transaction', 'created', 'exception', 'transaction'], array_column($events, 'event'));
    }

    public function test_inner_commit_does_not_survive_outer_rollback(): void
    {
        TraceLog::watch();
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'outer rolled back']);
        DB::beginTransaction();
        TransactionTestModel::create(['name' => 'inner rolled back']);
        DB::commit();
        DB::rollBack();

        $events = array_values(array_filter(
            $this->trace()['events'],
            static fn (array $event): bool => $event['event'] === 'created'
        ));
        $this->assertSame(['rolled_back', 'rolled_back'], array_column($events, 'transaction_status'));
        $this->assertSame([], TransactionTestModel::query()->pluck('name')->all());
    }

    public function test_watch_started_inside_transaction_is_unknown_without_begin_history(): void
    {
        DB::beginTransaction();
        TraceLog::watch();
        TransactionTestModel::create(['name' => 'partially observed']);
        DB::commit();

        $event = array_values(array_filter(
            $this->trace()['events'],
            static fn (array $event): bool => $event['event'] === 'created'
        ))[0];
        $this->assertSame('unknown', $event['transaction_status']);
    }

    /** @return array<string, mixed> */
    private function trace(): array
    {
        $this->assertFileExists($this->log_path);
        $event = json_decode(file($this->log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0], true, 512, JSON_THROW_ON_ERROR);

        return app(TraceReader::class)->trace(basename($this->log_path), $event['trace_id']);
    }

    private function clearLogs(): void
    {
        foreach (glob(storage_path('logs/tracelog-'.now()->format('Y-m-d').'-*.log')) ?: [] as $path) {
            unlink($path);
        }

        @unlink(storage_path('logs/tracelog.log.lock'));
    }
}

class TransactionTestModel extends Model
{
    protected $table = 'transaction_test_models';

    public $timestamps = false;

    protected $guarded = [];
}
