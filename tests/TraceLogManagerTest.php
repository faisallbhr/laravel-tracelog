<?php

namespace TraceLog\Tests;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TraceLog\Enums\ModelEvent;
use TraceLog\Facades\TraceLog;
use TraceLog\Internal\SourceResolver;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\TraceWriter;
use TraceLog\Internal\TransactionTracker;
use TraceLog\Internal\WatchRegistry;
use TraceLog\TraceLogManager;

class TraceLogManagerTest extends TestCase
{
    public function test_watch_all_models(): void
    {
        $manager = $this->manager();

        $manager->watch();

        $this->assertTrue($manager->isWatching(new TestModel));
    }

    public function test_watch_specific_model_class(): void
    {
        $manager = $this->manager();

        $manager->watch(TestModel::class);

        $this->assertTrue($manager->isWatching(new TestModel));

        $this->assertFalse($manager->isWatching(new OtherTestModel));
    }

    public function test_watch_multiple_model_classes(): void
    {
        $manager = $this->manager();

        $manager->watch(models: [TestModel::class, OtherTestModel::class, TestModel::class]);

        $this->assertTrue($manager->isWatching(new TestModel));
        $this->assertTrue($manager->isWatching(new OtherTestModel));
        $this->assertFalse($manager->isWatching(new ThirdTestModel));
        $this->assertCount(2, $manager->snapshot()['watch']['classes']);
    }

    public function test_global_watch_can_exclude_model_classes(): void
    {
        $manager = $this->manager();

        $manager->watch(except: [OtherTestModel::class, OtherTestModel::class]);

        $this->assertTrue($manager->isWatching(new TestModel));
        $this->assertFalse($manager->isWatching(new OtherTestModel));
        $this->assertSame(
            [OtherTestModel::class],
            $manager->snapshot()['watch']['all']['except_models']
        );
    }

    public function test_selected_models_can_be_excluded(): void
    {
        $manager = $this->manager();

        $manager->watch(
            models: [TestModel::class, OtherTestModel::class],
            except: [OtherTestModel::class],
        );

        $this->assertTrue($manager->isWatching(new TestModel));
        $this->assertFalse($manager->isWatching(new OtherTestModel));
    }

    public function test_empty_model_arguments_have_explicit_meanings(): void
    {
        $only_manager = $this->manager();
        $except_manager = $this->manager();

        $only_manager->watch(models: []);
        $except_manager->watch(except: []);

        $this->assertFalse($only_manager->isWatching(new TestModel));
        $this->assertTrue($except_manager->isWatching(new TestModel));
    }

    public function test_excluded_specific_watch_falls_back_to_an_eligible_global_watch(): void
    {
        $manager = $this->manager();

        $manager->watch();
        $manager->watch(models: TestModel::class, except: [TestModel::class]);

        $this->assertTrue($manager->isWatching(new TestModel));
    }

    public function test_exclusion_does_not_leak_into_an_independent_watch(): void
    {
        $manager = $this->manager();

        $manager->watch(except: [TestModel::class]);
        $manager->watch(TestModel::class);

        $this->assertTrue($manager->isWatching(new TestModel));
    }

    public function test_watch_specific_record(): void
    {
        $manager = $this->manager();

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
        $manager = $this->manager();

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

    public function test_record_watch_can_be_excluded_by_its_model_class(): void
    {
        $manager = $this->manager();
        $model = new TestModel;
        $model->id = 10;

        $manager->watch(models: $model, except: [TestModel::class]);

        $this->assertFalse($manager->isWatching($model));
    }

    public function test_stop_clears_all_watchers(): void
    {
        $manager = $this->manager();

        $manager->watch();
        $manager->stop();

        $this->assertFalse($manager->isWatching(new TestModel));
    }

    public function test_most_specific_watch_configuration_wins(): void
    {
        $registry = new WatchRegistry;
        $manager = $this->manager($registry);
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
        $manager = $this->manager($registry);

        $manager->watch(TestModel::class);
        $first = $registry->rulesFor(new TestModel);

        $manager->watch(TestModel::class);
        $second = $registry->rulesFor(new TestModel);

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $registry->rulesFor(new TestModel));
    }

    public function test_watch_configuration_can_be_restored_for_a_queued_job(): void
    {
        $manager = $this->manager();

        $manager->watch(
            models: [TestModel::class, OtherTestModel::class],
            except: [OtherTestModel::class],
        )
            ->only(['name'])
            ->except(['password'])
            ->events([ModelEvent::Updated])
            ->tags('checkout');

        $snapshot = $manager->snapshot();
        $restored = $this->manager();
        $restored->restore($snapshot);

        $this->assertTrue($restored->isWatching(new TestModel));
        $this->assertFalse($restored->isWatching(new OtherTestModel));
        $this->assertSame($snapshot, $restored->snapshot());
    }

    public function test_restoring_an_empty_queue_payload_clears_the_current_watch(): void
    {
        $manager = $this->manager();
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
        $manager = $this->manager();

        $this->expectException(InvalidArgumentException::class);

        $manager->watch(new TestModel);
    }

    public function test_invalid_model_selection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('watch models must contain only Eloquent model class names');

        $this->manager()->watch([TestModel::class, 'NotAModel']);
    }

    public function test_invalid_model_exclusion_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('watch exclusions must contain only Eloquent model class names');

        $this->manager()->watch(except: ['NotAModel']);
    }

    public function test_model_selection_must_be_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('watch models must be a list');

        $this->manager()->watch(models: ['model' => TestModel::class]);
    }

    private function manager(?WatchRegistry $registry = null): TraceLogManager
    {
        return new TraceLogManager(
            $registry ?? new WatchRegistry,
            new TraceContext,
            app(TraceWriter::class),
            new SourceResolver,
            app(TransactionTracker::class),
        );
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

class ThirdTestModel extends Model
{
    protected $table = 'third_test_models';

    public $timestamps = false;
}
