<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
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

        $this->log_path = storage_path('logs/tracelog.log');

        if (file_exists($this->log_path)) {
            unlink($this->log_path);
        }

        Schema::create('trace_test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
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
        $this->assertSame([
            'old' => 'Before',
            'new' => null,
        ], $context['changes']['name']);
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

        preg_match('/Eloquent event fired (\{.*\})/', $content, $matches);

        $this->assertArrayHasKey(1, $matches);

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_event_contains_origin(): void
    {
        TraceLog::watch();

        $expected_line = __LINE__ + 1;
        TraceTestModel::create(['name' => 'Origin Test']);

        $context = $this->readLogContext();

        $this->assertIsArray($context['origin']);
        $this->assertSame(__FILE__, $context['origin']['file']);
        $this->assertSame($expected_line, $context['origin']['line']);
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
