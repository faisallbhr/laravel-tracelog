<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TraceLog\Enums\ModelEvent;
use TraceLog\Facades\TraceLog;
use TraceLog\Internal\WatchRegistry;
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

    public function test_record_watch_does_not_match_the_same_key_on_another_connection(): void
    {
        $manager = new TraceLogManager;

        $watched = new TestModel;
        $watched->id = 10;
        $watched->setConnection('tenant_a');

        $other = new TestModel;
        $other->id = 10;
        $other->setConnection('tenant_b');

        $manager->watch($watched);

        $this->assertTrue($manager->isWatching($watched));
        $this->assertFalse($manager->isWatching($other));
    }

    public function test_stop_clears_all_watchers(): void
    {
        $manager = new TraceLogManager;

        $manager->watch();
        $manager->stop();

        $this->assertFalse($manager->isWatching(new TestModel));
    }

    public function test_most_specific_watch_configuration_wins(): void
    {
        $registry = new WatchRegistry;
        $manager = new TraceLogManager($registry);
        $model = new TestModel;
        $model->id = 10;

        $manager->watch();
        $global = $registry->rulesFor(new OtherTestModel);

        $manager->watch(TestModel::class);
        $class = $registry->rulesFor($model);

        $manager->watch($model);
        $record = $registry->rulesFor($model);

        $other_record = new TestModel;
        $other_record->id = 11;

        $this->assertSame($record, $registry->rulesFor($model));
        $this->assertSame($class, $registry->rulesFor($other_record));
        $this->assertSame($global, $registry->rulesFor(new OtherTestModel));
    }

    public function test_repeated_watch_replaces_its_configuration(): void
    {
        $registry = new WatchRegistry;
        $manager = new TraceLogManager($registry);

        $manager->watch(TestModel::class);
        $first = $registry->rulesFor(new TestModel);

        $manager->watch(TestModel::class);
        $second = $registry->rulesFor(new TestModel);

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $registry->rulesFor(new TestModel));
    }

    public function test_watch_configuration_can_be_restored_for_a_queued_job(): void
    {
        $manager = new TraceLogManager;

        $manager->watch(TestModel::class)
            ->only(['name'])
            ->events([ModelEvent::Updated])
            ->tags('checkout');

        $snapshot = $manager->snapshot();
        $restored = new TraceLogManager;
        $restored->restore($snapshot);

        $this->assertTrue($restored->isWatching(new TestModel));
        $this->assertSame($snapshot, $restored->snapshot());
    }

    public function test_restoring_an_empty_queue_payload_clears_the_current_watch(): void
    {
        $manager = new TraceLogManager;
        $manager->watch();

        $manager->restore(null);

        $this->assertFalse($manager->isWatching(new TestModel));
    }

    public function test_scoped_lifecycle_does_not_leak_watch_configuration(): void
    {
        TraceLog::watch()->only([]);
        $manager = $this->app->make(TraceLogManager::class);

        $this->app->forgetScopedInstances();

        $next_manager = $this->app->make(TraceLogManager::class);

        $this->assertNotSame($manager, $next_manager);
        $this->assertFalse(TraceLog::isWatching(new TestModel));
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
