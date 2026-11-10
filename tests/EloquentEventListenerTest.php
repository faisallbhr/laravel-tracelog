<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TraceLog\Concerns\Traceable;
use TraceLog\Enums\ModelEvent;
use TraceLog\Facades\TraceLog;

class EloquentEventListenerTest extends TestCase
{
    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log_path = storage_path('logs/tracelog-test.log');

        config([
            'logging.default' => 'tracelog-test',
            'logging.channels.tracelog-test' => [
                'driver' => 'single',
                'path' => $this->log_path,
            ],
        ]);

        if (file_exists($this->log_path)) {
            unlink($this->log_path);
        }

        Schema::create('trace_test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->integer('total')->nullable();
            $table->text('notes')->nullable();
            $table->string('password')->nullable();
            $table->string('pin')->nullable();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        TraceLog::stop();

        if (file_exists($this->log_path)) {
            unlink($this->log_path);
        }

        parent::tearDown();
    }

    public function test_created_event(): void
    {
        TraceLog::watch();

        $model = TraceTestModel::create([
            'name' => 'Before',
        ]);

        $context = $this->readLogContext();

        $this->assertSame('created', $context['event']);
        $this->assertSame(TraceTestModel::class, $context['model']);
        $this->assertEquals($model->getKey(), $context['id']);
        $this->assertSame([
            'old' => null,
            'new' => 'Before',
        ], $context['changes']['name']);
    }

    public function test_updated_event(): void
    {
        $model = TraceTestModel::create([
            'name' => 'Before',
        ]);

        TraceLog::watch();

        $model->update([
            'name' => 'After',
        ]);

        $context = $this->readLogContext();

        $this->assertSame('updated', $context['event']);
        $this->assertSame(['name'], array_keys($context['changes']));
        $this->assertSame([
            'old' => 'Before',
            'new' => 'After',
        ], $context['changes']['name']);
    }

    public function test_deleted_event(): void
    {
        $model = TraceTestModel::create([
            'name' => 'Before',
        ]);

        TraceLog::watch();

        $model->delete();

        $context = $this->readLogContext();

        $this->assertSame('deleted', $context['event']);
        $this->assertArrayNotHasKey('changes', $context);
    }

    public function test_restored_event(): void
    {
        $model = TraceTestModel::create([
            'name' => 'Before',
        ]);
        $model->delete();

        TraceLog::watch();

        $model->restore();

        $context = $this->readLogContext();

        $this->assertSame(2, substr_count(file_get_contents($this->log_path), '"event":'));
        $this->assertSame('restored', $context['event']);
        $this->assertNotNull($context['changes']['deleted_at']['old']);
        $this->assertNull($context['changes']['deleted_at']['new']);
    }

    public function test_force_deleted_event(): void
    {
        $model = TraceTestModel::create([
            'name' => 'Before',
        ]);
        $model->delete();

        TraceLog::watch();

        $model->forceDelete();

        $context = $this->readLogContext();
        $log = file_get_contents($this->log_path);

        $this->assertSame(2, substr_count($log, '"event":'));
        $this->assertStringContainsString('"event": "deleted"', $log);
        $this->assertSame('forceDeleted', $context['event']);
        $this->assertArrayNotHasKey('changes', $context);
    }

    public function test_manually_clearing_deleted_at_is_logged_as_an_update(): void
    {
        $model = TraceTestModel::create([
            'name' => 'Before',
        ]);
        $model->delete();

        TraceLog::watch();

        $model->deleted_at = null;
        $model->save();

        $context = $this->readLogContext();

        $this->assertSame('updated', $context['event']);
        $this->assertNotNull($context['changes']['deleted_at']['old']);
        $this->assertNull($context['changes']['deleted_at']['new']);
    }

    public function test_attribute_filters_apply_before_logging(): void
    {
        $model = TraceTestModel::create(['name' => 'Before']);

        TraceLog::watch(TraceTestModel::class)
            ->only(['name', 'status', 'notes'])
            ->except(['notes']);

        $model->update([
            'name' => 'After',
            'status' => 'paid',
            'notes' => 'Payment received',
        ]);

        $context = $this->readLogContext();

        $this->assertSame(['name', 'status'], array_keys($context['changes']));
    }

    public function test_update_is_skipped_when_filter_removes_all_changes(): void
    {
        $model = TraceTestModel::create(['name' => 'Before']);

        TraceLog::watch($model)->only([]);

        $model->update(['name' => 'After']);

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_unrelated_update_is_skipped_by_non_empty_allowlist(): void
    {
        $model = TraceTestModel::create(['name' => 'Before']);

        TraceLog::watch($model)->only(['status']);

        $model->update(['name' => 'After']);

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_lifecycle_event_is_logged_when_filter_removes_its_changes(): void
    {
        TraceLog::watch(TraceTestModel::class)->only([]);

        TraceTestModel::create(['name' => 'Before']);

        $context = $this->readLogContext();

        $this->assertSame('created', $context['event']);
        $this->assertArrayNotHasKey('changes', $context);
    }

    public function test_event_filter_only_logs_selected_events(): void
    {
        $model = TraceTestModel::create(['name' => 'Before']);

        TraceLog::watch($model)->events([ModelEvent::Deleted]);

        $model->update(['name' => 'After']);
        $this->assertFileDoesNotExist($this->log_path);

        $model->delete();

        $this->assertSame('deleted', $this->readLogContext()['event']);
    }

    public function test_empty_event_filter_disables_watch_logging(): void
    {
        TraceLog::watch()->events([]);

        TraceTestModel::create(['name' => 'Ignored']);

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_event_filter_removes_native_companion_events(): void
    {
        $model = TraceTestModel::create(['name' => 'Before']);
        $model->delete();

        TraceLog::watch($model)
            ->only([])
            ->events([ModelEvent::Restored]);

        $model->restore();

        $this->assertSame('restored', $this->readLogContext()['event']);
        $this->assertSame(1, substr_count(file_get_contents($this->log_path), '"event":'));

        TraceLog::watch($model)
            ->only([])
            ->events([ModelEvent::ForceDeleted]);

        $model->forceDelete();

        $this->assertSame('forceDeleted', $this->readLogContext()['event']);
        $this->assertSame(2, substr_count(file_get_contents($this->log_path), '"event":'));
    }

    public function test_sensitive_attributes_are_redacted_in_log(): void
    {
        $model = TraceTestModel::create([
            'name' => 'Jane',
            'password' => 'old-password',
            'pin' => '1234',
        ]);

        TraceLog::watch($model)
            ->only(['name', 'password', 'pin'])
            ->sensitive(['pin']);

        $model->update([
            'name' => 'John',
            'password' => 'new-password',
            'pin' => '5678',
        ]);

        $changes = $this->readLogContext()['changes'];
        $redacted = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];

        $this->assertSame(['old' => 'Jane', 'new' => 'John'], $changes['name']);
        $this->assertSame($redacted, $changes['password']);
        $this->assertSame($redacted, $changes['pin']);
    }

    public function test_record_filter_takes_precedence_over_class_and_global_filters(): void
    {
        $model = TraceTestModel::create(['name' => 'Before', 'status' => 'pending']);

        TraceLog::watch()->only(['total']);
        TraceLog::watch(TraceTestModel::class)->only(['status']);
        TraceLog::watch($model)->only(['name']);

        $model->update(['name' => 'After', 'status' => 'paid', 'total' => 150]);

        $this->assertSame(['name'], array_keys($this->readLogContext()['changes']));
        $this->assertSame(1, substr_count(file_get_contents($this->log_path), '"event":'));
    }

    public function test_class_filter_takes_precedence_and_global_filter_is_the_fallback(): void
    {
        $class_model = TraceTestModel::create(['name' => 'Class', 'status' => 'pending', 'total' => 100]);
        $global_model = OtherTraceTestModel::create(['name' => 'Global', 'total' => 100]);

        TraceLog::watch()->only(['total']);
        TraceLog::watch(TraceTestModel::class)->only(['status']);

        $class_model->update(['status' => 'paid', 'total' => 150]);
        $this->assertSame(['status'], array_keys($this->readLogContext()['changes']));

        $global_model->update(['name' => 'Changed', 'total' => 200]);
        $this->assertSame(['total'], array_keys($this->readLogContext()['changes']));
        $this->assertSame(2, substr_count(file_get_contents($this->log_path), '"event":'));
    }

    public function test_repeated_scope_registration_replaces_old_filters(): void
    {
        $model = TraceTestModel::create(['name' => 'Before', 'status' => 'pending']);

        TraceLog::watch(TraceTestModel::class)->only(['status']);
        TraceLog::watch(TraceTestModel::class)->only(['name']);

        $model->update(['name' => 'After', 'status' => 'paid']);

        $this->assertSame(['name'], array_keys($this->readLogContext()['changes']));
    }

    public function test_unwatched_model_is_not_logged(): void
    {
        TraceTestModel::create([
            'name' => 'Ignored',
        ]);

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_stop_prevents_logging(): void
    {
        TraceLog::watch();
        TraceLog::stop();

        TraceTestModel::create([
            'name' => 'Ignored',
        ]);

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_stop_clears_filters_before_a_new_watch(): void
    {
        $model = TraceTestModel::create(['name' => 'Before']);

        TraceLog::watch($model)->events([]);
        TraceLog::stop();
        TraceLog::watch($model);

        $model->update(['name' => 'After']);

        $this->assertSame('updated', $this->readLogContext()['event']);
    }

    private function readLogContext(): array
    {
        $this->assertFileExists($this->log_path);

        $content = file_get_contents($this->log_path);

        $start = strrpos($content, 'DEBUG: ');

        $this->assertNotFalse($start);

        return json_decode(substr($content, $start + 7), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_event_contains_source(): void
    {
        TraceLog::watch();

        $expected_line = __LINE__ + 1;
        TraceTestModel::create(['name' => 'Origin Test']);

        $context = $this->readLogContext();

        $this->assertSame(__FILE__.':'.$expected_line, $context['source']);
    }

    public function test_traceable_model_is_logged_without_watch(): void
    {
        TraceableTestModel::create([
            'name' => 'Automatically Traced',
        ]);

        $context = $this->readLogContext();

        $this->assertSame('created', $context['event']);
        $this->assertSame(TraceableTestModel::class, $context['model']);
        $this->assertSame(
            'Automatically Traced',
            $context['changes']['name']['new']
        );
    }

    public function test_traceable_model_redacts_sensitive_attributes(): void
    {
        TraceableTestModel::create([
            'name' => 'Automatically Traced',
            'password' => 'secret-password',
        ]);

        $context = $this->readLogContext();

        $this->assertSame('[REDACTED]', $context['changes']['password']['new']);
    }

    public function test_stop_does_not_disable_traceable_model(): void
    {
        TraceLog::stop();

        TraceableTestModel::create([
            'name' => 'Still Traced',
        ]);

        $context = $this->readLogContext();

        $this->assertSame('created', $context['event']);
    }
}

class TraceTestModel extends Model
{
    use SoftDeletes;

    protected $table = 'trace_test_models';

    protected $fillable = ['name', 'status', 'total', 'notes', 'password', 'pin'];

    public $timestamps = false;
}

class TraceableTestModel extends Model
{
    use Traceable;

    protected $table = 'trace_test_models';

    protected $fillable = ['name', 'status', 'total', 'notes', 'password', 'pin'];

    public $timestamps = false;
}

class OtherTraceTestModel extends Model
{
    protected $table = 'trace_test_models';

    protected $fillable = ['name', 'status', 'total', 'notes', 'password', 'pin'];

    public $timestamps = false;
}
