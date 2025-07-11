<?php

namespace TightenCo\Jigsaw\Console;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TightenCo\Jigsaw\File\ConfigFile;
use TightenCo\Jigsaw\File\TemporaryFilesystem;
use TightenCo\Jigsaw\Jigsaw;
use TightenCo\Jigsaw\PathResolvers\PrettyOutputPathResolver;

class BuildCommand extends Command
{
    private $app;

    private $consoleOutput;

    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->consoleOutput = $app->consoleOutput;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('build')
            ->setDescription('Build your site.')
            ->addArgument('env', InputArgument::OPTIONAL, 'What environment should we use to build?', 'local')
            ->addOption('pretty', null, InputOption::VALUE_REQUIRED, 'Should the site use pretty URLs?', 'true')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Should watch for file changes and rebuild?')
            ->addOption('cache', 'c', InputOption::VALUE_OPTIONAL, 'Should a cache be used when building the site?', 'false');
    }

    protected function fire()
    {
        if ($this->input->getOption('watch')) {
            return $this->watch();
        }
        
        return $this->build();
    }

    private function build()
    {
        $startTime = microtime(true);
        $env = $this->app['env'] = $this->input->getArgument('env');
        $this->includeEnvironmentConfig($env);
        $this->updateBuildPaths($env);
        $cacheExists = $this->app[TemporaryFilesystem::class]->hasTempDirectory();

        if ($this->input->getOption('pretty') === 'true' && $this->app->config->get('pretty') !== false) {
            $this->app->instance('outputPathResolver', new PrettyOutputPathResolver);
        }

        if ($this->input->getOption('quiet')) {
            $verbosity = OutputInterface::VERBOSITY_QUIET;
        } elseif ($this->input->getOption('verbose')) {
            $verbosity = OutputInterface::VERBOSITY_VERBOSE;
        } else {
            $verbosity = OutputInterface::VERBOSITY_NORMAL;
        }

        $this->consoleOutput->setup($verbosity);
        $this->consoleOutput->writeIntro($env, $this->useCache(), $cacheExists);

        if ($this->confirmDestination()) {
            try {
                $this->app->make(Jigsaw::class)->build($this->useCache());
            } catch (Throwable $e) {
                $this->app->make(ExceptionHandler::class)->report($e);
                $this->app->make(ExceptionHandler::class)->renderForConsole($this->consoleOutput, $e);

                return static::FAILURE;
            }

            $this->consoleOutput
                ->writeTime(round(microtime(true) - $startTime, 2), $this->useCache(), $cacheExists)
                ->writeConclusion();
        }
    }

    private function watch()
    {
        $sourcePath = $this->app->buildPath['source'];
        $fileTimestamps = [];

        $scanFiles = function ($dir) {
            $files = [];

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $files[$file->getPathname()] = $file->getMTime();
                    }
                }
            } catch (Throwable $e) {
                $this->app->make(ExceptionHandler::class)->report($e);
                $this->app->make(ExceptionHandler::class)->renderForConsole($this->consoleOutput, $e);

                return static::FAILURE;
            }

            return $files;
        };

        $fileTimestamps = $scanFiles($sourcePath);
        $this->consoleOutput->writeln('<info>Watching for changes in ' . $sourcePath . '</info>');
        $iterationCount = 0;

        while (true) {
            try {
                $currentTimestamps = $scanFiles($sourcePath);
                $affectedFiles = 0;

                foreach ($currentTimestamps as $file => $mtime) {
                    if (!isset($fileTimestamps[$file]) || $fileTimestamps[$file] !== $mtime) {
                        $affectedFiles++;
                        $this->consoleOutput->writeln('<info>File changed: ' . $file . '</info>');
                    }
                }

                foreach ($fileTimestamps as $file => $mtime) {
                    if (!isset($currentTimestamps[$file])) {
                        $affectedFiles++;
                        $this->consoleOutput->writeln('<info>File deleted: ' . $file . '</info>');
                    }
                }

                if ($affectedFiles > 0) {
                    $this->build();
                }

                $fileTimestamps = $currentTimestamps;
                unset($currentTimestamps);
                clearstatcache();

                if (++$iterationCount % 10 === 0) {
                    gc_collect_cycles();
                }

                usleep(1000000);

            } catch (Throwable $e) {
                $this->app->make(ExceptionHandler::class)->report($e);
                $this->app->make(ExceptionHandler::class)->renderForConsole($this->consoleOutput, $e);

                return static::FAILURE;
            }

            if (file_exists($sourcePath . '/.stopwatch')) {
                $this->consoleOutput->writeln('<info>Stopping watcher...</info>');
                break;
            }
        }
    }

    private function useCache()
    {
        return $this->input->getOption('cache') !== 'false' || $this->app->config->get('cache');
    }

    private function includeEnvironmentConfig($env)
    {
        $environmentConfigPath = $this->getAbsolutePath("config.{$env}.php");
        $environmentConfig = (new ConfigFile($environmentConfigPath))->config;

        $baseConfig = $this->app->config;

        $this->app->config = collect($baseConfig)
            ->merge(collect($environmentConfig))
            ->filter(function ($item) {
                return $item !== null;
            });

        if ($this->app->config->get('merge_collection_configs')) {
            $this->app->config->put('collections', $this->app->config->get('collections')->map(
                function ($envConfig, $key) use ($baseConfig) {
                    return array_merge($baseConfig->get('collections')->get($key), $envConfig);
                },
            ));
        }
    }

    private function updateBuildPaths($env)
    {
        $this->app->buildPath = [
            'source' => $this->getBuildPath('source', $env),
            'views' => $this->getBuildPath('views', $env) ?: $this->getBuildPath('source', $env),
            'destination' => $this->getBuildPath('destination', $env),
        ];
    }

    private function getBuildPath($pathType, $env)
    {
        $customPath = Arr::get($this->app->config, 'build.' . $pathType);
        $buildPath = $customPath
            ? $this->getAbsolutePath($customPath)
            : Arr::get($this->app->buildPath, $pathType);

        return str_replace('{env}', $env, $buildPath ?? '');
    }

    private function getAbsolutePath($path)
    {
        return $this->app->cwd . '/' . trimPath($path);
    }

    private function confirmDestination()
    {
        if ($this->input->getOption('quiet') || $this->input->getOption('watch')) {
            return true;
        }
        
        $customPath = Arr::get($this->app->config, 'build.destination');

        if ($customPath && strpos($customPath, 'build_') !== 0 && file_exists($customPath)) {
            return $this->console->confirm('Overwrite "' . $this->app->buildPath['destination'] . '"? ');
        }

        return true;
    }
}
