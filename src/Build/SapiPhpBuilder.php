<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use TypePhp\Installer\PhpBuildConfiguration;

final class SapiPhpBuilder
{
    private readonly \Closure $output;

    /** @param callable(string):void $output */
    public function __construct(
        private readonly string $phpxSourceDirectory,
        callable $output,
        private readonly ?string $proxy = null,
    ) {
        $this->output = \Closure::fromCallable($output);
    }

    /**
     * @param list<string> $targets
     * @param list<string> $requiredExtensions
     */
    public function prepare(
        string $phpVersion,
        array $targets,
        int $jobs,
        array $requiredExtensions = [],
        bool $zts = false,
    ): SapiPhpBuild {
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException('Self-contained SAPI builds currently require Linux or macOS');
        }
        if ($targets === [] || array_diff($targets, ['embed', 'cli', 'fpm']) !== []) {
            throw new \InvalidArgumentException('PHP builder SAPI targets must contain embed, cli, or fpm');
        }
        $officialSource = (new OfficialPhpSource(
            OfficialPhpSource::defaultCacheDirectory(),
            $this->output,
            $this->proxy,
        ))->prepare($phpVersion);
        $preparedSource = (new PhpBuilderSource(
            OfficialPhpSource::defaultCacheDirectory(),
            $this->output,
            $this->proxy,
        ))->prepare($officialSource, $requiredExtensions);
        $source = $preparedSource['source'];
        $externalExtensions = $preparedSource['external'];
        $sourceVersion = OfficialPhpSource::version($source);
        $baseOptions = $this->sourceConfigureOptions($source);
        $requiredExtensions = SapiExtensionRequirements::merge($requiredExtensions);
        $extensionOptions = SapiExtensionConfiguration::configureOptions($source, $requiredExtensions);
        $runtimeTargets = array_values(array_unique($targets));
        sort($runtimeTargets, SORT_STRING);
        $identity = [
            $sourceVersion,
            $baseOptions,
            $runtimeTargets,
            $zts,
            $externalExtensions,
            PHP_OS_FAMILY,
            php_uname('m'),
            getenv('CC') ?: '',
            getenv('CXX') ?: '',
            filemtime($source . '/configure'),
        ];
        $compatibility = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $cacheDirectory = OfficialPhpSource::defaultCacheDirectory() . '/php-builder';
        $cached = $this->findCompatibleRuntime(
            $cacheDirectory,
            $sourceVersion,
            $compatibility,
            $requiredExtensions,
        );
        if ($cached !== null) {
            ($this->output)('Reusing private PHP runtime with extensions: '
                . ($requiredExtensions === [] ? 'core' : implode(', ', $requiredExtensions)));
            return $cached;
        }

        // Keep the original no-extension key stable so runtimes created by an
        // earlier TypePHP version can be adopted without rebuilding php-src.
        $fingerprintInput = $extensionOptions === [] ? $identity : [...$identity, $extensionOptions];
        $fingerprint = substr(hash('sha256', json_encode($fingerprintInput, JSON_THROW_ON_ERROR)), 0, 16);
        $root = $cacheDirectory . '/php-'
            . $sourceVersion . '-' . $fingerprint;
        $this->mkdir($root);
        $lock = fopen($root . '/build.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Unable to lock PHP builder runtime cache: {$root}");
        }
        try {
            $build = $root . '/build';
            $prefix = $root . '/install';
            $this->mkdir($build);
            $this->mkdir($prefix . '/lib/conf.d');

            $options = PhpBuildConfiguration::derivePhpBuilder(
                [...$baseOptions, ...$extensionOptions],
                $prefix,
                $runtimeTargets,
                $zts,
            );
            $configured = $build . '/.typephp-configure.json';
            $configuration = json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!is_file($build . '/Makefile') || @file_get_contents($configured) !== $configuration) {
                ($this->output)('Configuring PHP ' . $sourceVersion . ' source runtime');
                $this->run([$source . '/configure', ...$options], $build);
                AtomicFile::write($configured, $configuration);
            }

            $php = $prefix . '/bin/php';
            $fpm = $prefix . '/sbin/php-fpm';
            $embed = $prefix . '/lib/libphp.a';
            $needsInstall = !is_executable($php)
                || (in_array('fpm', $runtimeTargets, true) && !is_executable($fpm))
                || (in_array('embed', $runtimeTargets, true) && !is_file($embed));
            if ($needsInstall) {
                ($this->output)('Building private PHP runtime (cached across application builds)');
                $this->run(['make', '-j' . max(1, $jobs)], $build);
                $this->run(['make', 'install'], $build);
            }

            $sapiArchives = $this->buildSapiArchives($build, $root, $jobs, $runtimeTargets, $embed);

            $phpxBuild = $root . '/phpx-build';
            $phpxArchive = $phpxBuild . '/lib/libphpx.a';
            if (!is_file($phpxArchive)) {
                ($this->output)('Building static PHPX runtime');
                $this->run([
                    'cmake',
                    '-S', $this->phpxSourceDirectory . '/sapi-static',
                    '-B', $phpxBuild,
                    '-DCMAKE_BUILD_TYPE=Release',
                    '-DPHPX_ROOT=' . $this->phpxSourceDirectory,
                    '-DPHPX_PHP_PREFIX=' . $prefix,
                ], $root);
                $this->run([
                    'cmake', '--build', $phpxBuild, '--parallel', (string) max(1, $jobs),
                ], $root);
            }
            if (!is_executable($php) || !is_file($phpxArchive)) {
                throw new \RuntimeException('Private SAPI runtime build did not produce PHP CLI and libphpx.a');
            }
            $enabledExtensions = $this->detectEnabledExtensions($php);
            $missing = array_values(array_diff($requiredExtensions, $enabledExtensions));
            if ($missing !== []) {
                throw new \RuntimeException('Private PHP runtime is missing required extensions after build: ' . implode(', ', $missing));
            }
            AtomicFile::write($root . '/runtime.json', json_encode([
                'version' => $sourceVersion,
                'source' => $source,
                'compatibility' => $compatibility,
                'requested_extensions' => $requiredExtensions,
                'enabled_extensions' => $enabledExtensions,
                'configure_options' => $options,
                'sapis' => $runtimeTargets,
                'zts' => $zts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            return new SapiPhpBuild(
                $source,
                $build,
                $prefix,
                $phpxArchive,
                $sapiArchives,
                $sourceVersion,
                $enabledExtensions,
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<string> $requiredExtensions */
    private function findCompatibleRuntime(
        string $cacheDirectory,
        string $sourceVersion,
        string $compatibility,
        array $requiredExtensions,
    ): ?SapiPhpBuild {
        $matches = [];
        foreach (glob($cacheDirectory . '/php-' . $sourceVersion . '-*') ?: [] as $root) {
            $manifest = $root . '/runtime.json';
            if (!is_file($manifest)) {
                continue;
            }
            $metadata = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($metadata) || ($metadata['compatibility'] ?? null) !== $compatibility) {
                continue;
            }
            $enabled = SapiExtensionRequirements::merge(
                is_array($metadata['enabled_extensions'] ?? null) ? $metadata['enabled_extensions'] : [],
            );
            if (array_diff($requiredExtensions, $enabled) !== []) {
                continue;
            }
            $runtime = $this->runtimeFromRoot($root, $sourceVersion, $enabled, $requiredExtensions);
            if ($runtime !== null) {
                $matches[] = [$runtime, count($enabled), filemtime($manifest) ?: 0];
            }
        }
        usort($matches, static fn (array $left, array $right): int => $left[1] <=> $right[1] ?: $right[2] <=> $left[2]);
        return $matches[0][0] ?? null;
    }

    /** @param list<string> $enabledExtensions */
    private function runtimeFromRoot(
        string $root,
        string $version,
        array $enabledExtensions,
        array $requiredExtensions,
    ): ?SapiPhpBuild {
        $build = $root . '/build';
        $prefix = $root . '/install';
        $phpxArchive = $root . '/phpx-build/lib/libphpx.a';
        $metadata = json_decode((string) @file_get_contents($root . '/runtime.json'), true);
        $targets = is_array($metadata) && is_array($metadata['sapis'] ?? null)
            ? $metadata['sapis']
            : ['cli', 'fpm'];
        $archives = [];
        foreach ($targets as $target) {
            if ($target === 'embed') {
                $archives['embed'] = $prefix . '/lib/libphp.a';
            } elseif ($target === 'cli' || $target === 'fpm') {
                $archives[$target] = $root . '/lib/libphp-' . $target . '.a';
            }
        }
        if (!is_executable($prefix . '/bin/php')
            || !is_file($phpxArchive)
            || array_diff($requiredExtensions, $enabledExtensions) !== []
            || array_filter($archives, static fn (string $archive): bool => !is_file($archive)) !== []
            || !is_file($build . '/Makefile')
        ) {
            return null;
        }
        $source = is_array($metadata) && is_string($metadata['source'] ?? null)
            ? $metadata['source']
            : OfficialPhpSource::defaultCacheDirectory() . '/src/php-' . $version;
        if (!is_dir($source)) {
            return null;
        }
        return new SapiPhpBuild(
            $source,
            $build,
            $prefix,
            $phpxArchive,
            $archives,
            $version,
            $enabledExtensions,
        );
    }

    /** @return list<string> */
    private function detectEnabledExtensions(string $php): array
    {
        $process = proc_open(
            [$php, '-n', '-r', 'echo json_encode(get_loaded_extensions());'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException("Unable to inspect private PHP extensions: {$php}");
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $decoded = is_string($stdout) ? json_decode($stdout, true) : null;
        if ($status !== 0 || !is_array($decoded)) {
            throw new \RuntimeException("Unable to inspect private PHP extensions: {$php}" . (is_string($stderr) && trim($stderr) !== '' ? PHP_EOL . trim($stderr) : ''));
        }
        return SapiExtensionRequirements::merge(array_values(array_filter($decoded, 'is_string')));
    }

    /** @return array<string, string> */
    private function buildSapiArchives(
        string $build,
        string $root,
        int $jobs,
        array $targets,
        string $embedArchive,
    ): array
    {
        $libraryDirectory = $root . '/lib';
        $this->mkdir($libraryDirectory);
        $archives = [];
        foreach (array_intersect($targets, ['cli', 'fpm']) as $target) {
            $archives[$target] = $libraryDirectory . '/libphp-' . $target . '.a';
        }
        if (in_array('embed', $targets, true)) {
            $archives['embed'] = $embedArchive;
        }
        if (array_filter($archives, static fn (string $archive): bool => !is_file($archive)) === []) {
            return $archives;
        }
        // The normal PHP build creates every core and SAPI object. Archive
        // them once, excluding the two entry objects that TypePHP patches to
        // accept embedded primary scripts.
        $this->run(['make', '-j' . max(1, $jobs)], $build);
        $variables = $this->readMakeVariables($build . '/Makefile', [
            'PHP_GLOBAL_OBJS', 'PHP_CLI_OBJS', 'PHP_FPM_OBJS',
        ]);
        foreach ([
            'cli' => ['PHP_CLI_OBJS', 'sapi/cli/php_cli.lo'],
            'fpm' => ['PHP_FPM_OBJS', 'sapi/fpm/fpm/fpm_main.lo'],
        ] as $target => [$variable, $entryObject]) {
            if (!isset($archives[$target])) {
                continue;
            }
            if (is_file($archives[$target])) {
                continue;
            }
            $objects = $this->objectFiles(
                ($variables['PHP_GLOBAL_OBJS'] ?? '') . ' ' . ($variables[$variable] ?? ''),
                $entryObject,
                $build,
            );
            ($this->output)('Caching PHP ' . strtoupper($target) . ' runtime: ' . $archives[$target]);
            $temporary = $archives[$target] . '.part-' . bin2hex(random_bytes(6));
            $this->run(['ar', 'rcs', $temporary, ...$objects], $build);
            if (!rename($temporary, $archives[$target])) {
                @unlink($temporary);
                throw new \RuntimeException('Unable to store PHP SAPI archive: ' . $archives[$target]);
            }
        }
        if (isset($archives['embed']) && !is_file($archives['embed'])) {
            throw new \RuntimeException('PHP Embed static archive was not installed: ' . $archives['embed']);
        }
        return $archives;
    }

    /** @return list<string> */
    private function objectFiles(string $value, string $excluded, string $build): array
    {
        $objects = [];
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $object) {
            if ($object === '' || $object === $excluded) {
                continue;
            }
            $object = preg_replace('/\.lo$/', '.o', $object) ?? $object;
            $path = str_starts_with($object, '/') ? $object : $build . '/' . $object;
            if (!is_file($path)) {
                throw new \RuntimeException("PHP build object does not exist: {$path}");
            }
            $objects[] = $path;
        }
        if ($objects === []) {
            throw new \RuntimeException('PHP build did not publish objects for the requested SAPI archive');
        }
        return $objects;
    }

    /** @param list<string> $names @return array<string, string> */
    private function readMakeVariables(string $makefile, array $names): array
    {
        $contents = (string) file_get_contents($makefile);
        $values = [];
        foreach ($names as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . '[ \t]*=[ \t]*(.*)$/m', $contents, $match) === 1) {
                $values[$name] = trim($match[1]);
            }
        }
        return $values;
    }

    /** @return list<string> */
    private function sourceConfigureOptions(string $source): array
    {
        $configNice = $source . '/config.nice';
        if (!is_file($configNice)) {
            return ['--enable-zts', '--enable-mbstring', '--enable-sockets', '--with-zlib'];
        }
        $contents = (string) file_get_contents($configNice);
        $contents = preg_replace('/^#!.*\n|^#.*\n/m', '', $contents) ?? $contents;
        $contents = str_replace(["\\\r\n", "\\\n", '"$@"', "'$@'"], [' ', ' ', '', ''], $contents);
        $options = PhpBuildConfiguration::parseShellWords($contents);
        return array_values(array_filter($options, static fn (string $value): bool => str_starts_with($value, '--')));
    }

    /** @param list<string> $command */
    private function run(array $command, string $directory): void
    {
        ($this->output)('$ ' . implode(' ', array_map('escapeshellarg', $command)));
        $logPath = rtrim($directory, '/\\') . '/.typephp-build.log';
        $log = fopen($logPath, 'ab');
        if ($log === false) {
            throw new \RuntimeException("Unable to open SAPI build log: {$logPath}");
        }
        fwrite($log, PHP_EOL . '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);
        $process = proc_open($command, [STDIN, $log, $log], $pipes, $directory);
        $status = is_resource($process) ? proc_close($process) : -1;
        fclose($log);
        if ($status !== 0) {
            $contents = (string) @file_get_contents($logPath);
            $lines = preg_split('/\R/', trim($contents)) ?: [];
            $tail = implode(PHP_EOL, array_slice($lines, -40));
            throw new \RuntimeException('Command failed: ' . implode(' ', $command) . PHP_EOL . 'Build log: ' . $logPath . ($tail === '' ? '' : PHP_EOL . $tail));
        }
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create SAPI build directory: {$directory}");
        }
    }
}
