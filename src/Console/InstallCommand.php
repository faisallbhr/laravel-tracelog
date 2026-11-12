<?php

namespace TraceLog\Console;

use Illuminate\Console\Command;
use RuntimeException;

/** Installs the application provider used to configure TraceLog access. */
final class InstallCommand extends Command
{
    protected $signature = 'tracelog:install';

    protected $description = 'Install the TraceLog application service provider';

    public function handle(): int
    {
        $provider_path = app_path('Providers/TraceLogServiceProvider.php');

        if (is_file($provider_path)) {
            $this->components->warn('TraceLogServiceProvider already exists. Nothing changed.');

            return self::SUCCESS;
        }

        $provider_directory = dirname($provider_path);

        if (! is_dir($provider_directory) && ! mkdir($provider_directory, 0777, true) && ! is_dir($provider_directory)) {
            throw new RuntimeException('Unable to create the application providers directory.');
        }

        file_put_contents($provider_path, <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TraceLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewTraceLog', fn (?Authenticatable $user = null): bool => app()->environment('local'));
    }
}
PHP
        );

        $this->registerProvider();
        $this->components->info('TraceLogServiceProvider installed.');
        $this->line('Edit app/Providers/TraceLogServiceProvider.php to customize viewer access.');

        return self::SUCCESS;
    }

    private function registerProvider(): void
    {
        $bootstrap_providers_path = base_path('bootstrap/providers.php');

        if (is_file($bootstrap_providers_path)) {
            $contents = file_get_contents($bootstrap_providers_path);
            $provider = 'App\\Providers\\TraceLogServiceProvider::class,';

            if ($contents === false || str_contains($contents, $provider)) {
                return;
            }

            $position = strpos($contents, '[');

            if ($position === false) {
                throw new RuntimeException('Unable to register the TraceLog application service provider.');
            }

            $contents = substr_replace($contents, "[\n    {$provider}", $position, 1);
            file_put_contents($bootstrap_providers_path, $contents);

            return;
        }

        $config_path = config_path('app.php');
        $contents = file_get_contents($config_path);
        $provider = 'App\\Providers\\TraceLogServiceProvider::class,';

        if ($contents === false || str_contains($contents, $provider)) {
            return;
        }

        $application_provider = 'App\\Providers\\AppServiceProvider::class,';

        if (! str_contains($contents, $application_provider)) {
            throw new RuntimeException('Unable to find the application service provider list.');
        }

        $contents = str_replace(
            $application_provider,
            $application_provider."\n        {$provider}",
            $contents,
            $count
        );

        if ($count !== 1) {
            throw new RuntimeException('Unable to register the TraceLog application service provider.');
        }

        file_put_contents($config_path, $contents);
    }
}
