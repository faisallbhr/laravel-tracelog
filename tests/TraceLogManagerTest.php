<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TraceLog\TraceLogManager;

class TraceLogManagerTest extends TestCase
{
    public function test_watch_all_models(): void
    {
        $manager = new TraceLogManager;

        $manager->watch();

        $this->assertTrue($manager->isWatching(new TestModel));
    }

    public function test_watch_specific_model_class(): void
    {
        $manager = new TraceLogManager;

        $manager->watch(TestModel::class);

        $this->assertTrue($manager->isWatching(new TestModel));

        $this->assertFalse($manager->isWatching(new OtherTestModel));
    }

    public function test_watch_specific_record(): void
    {
        $manager = new TraceLogManager;

        $model = new TestModel;
        $model->id = 10;

        $manager->watch($model);

        $this->assertTrue($manager->isWatching($model));

        $other = new TestModel;
        $other->id = 11;

        $this->assertFalse($manager->isWatching($other));
    }

    public function test_stop_clears_all_watchers(): void
    {
        $manager = new TraceLogManager;

        $manager->watch();
        $manager->stop();

        $this->assertFalse($manager->isWatching(new TestModel));
    }

    public function test_cannot_watch_unsaved_model(): void
    {
        $manager = new TraceLogManager;

        $this->expectException(InvalidArgumentException::class);

        $manager->watch(new TestModel);
    }
}

class TestModel extends Model
{
    protected $table = 'test_models';

    public $timestamps = false;
}

class OtherTestModel extends Model
{
    protected $table = 'other_test_models';

    public $timestamps = false;
}
