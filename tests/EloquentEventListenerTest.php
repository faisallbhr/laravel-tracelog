<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TraceLog\Concerns\Traceable;
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

        $this->assertSame(1, substr_count(file_get_contents($this->log_path), '"event":'));
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

    protected $fillable = ['name'];

    public $timestamps = false;
}

class TraceableTestModel extends Model
{
    use Traceable;

    protected $table = 'trace_test_models';

    protected $fillable = ['name'];

    public $timestamps = false;
}
