<?php

namespace TypePhp\Build;

final class OfficialPhpSource
{
    private const string RELEASE_API = 'https://www.php.net/releases/index.php?json=1&version=%s&max=100';
    private readonly \Closure $output;

    /** @param callable(string):void $output */
    public function __construct(
        private readonly string $cacheDirectory,
        callable $output,
    ) {
        $this->output = \Closure::fromCallable($output);
    }

    public static function defaultCacheDirectory(?string $home = null): string
    {
        $home ??= getenv('HOME') ?: '';
        if ($home === '') {
            throw new \RuntimeException('Cannot determine the PHP source cache because HOME is not set');
        }
        return rtrim($home, '/\\') . '/.typephp';
    }

    public function prepare(string $requestedVersion): string
    {
        $cached = $this->findCachedSource($requestedVersion);
        if ($cached !== null) {
            return $cached;
        }
        $release = $this->resolveRelease($requestedVersion);
        $this->mkdir($this->cacheDirectory);
        $lock = fopen($this->cacheDirectory . '/download.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the PHP source cache: ' . $this->cacheDirectory);
        }
        try {
            return $this->prepareLocked($release);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function findCachedSource(string $requestedVersion): ?string
    {
        if (preg_match('/^(8\.(?:4|5))(?:\.0)?$/', $requestedVersion, $match) === 1) {
            $pattern = $this->cacheDirectory . '/src/php-' . $match[1] . '.*';
        } elseif (preg_match('/^8\.(?:4|5)\.\d+$/', $requestedVersion) === 1) {
            $pattern = $this->cacheDirectory . '/src/php-' . $requestedVersion;
        } else {
            return null;
        }
        $candidates = [];
        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $source) {
            if ($this->isComplete($source)) {
                $candidates[] = $source;
            }
        }
        usort($candidates, static fn(string $left, string $right): int => version_compare(
            substr(basename($right), 4),
            substr(basename($left), 4),
        ));
        return $candidates[0] ?? null;
    }

    /** @return array{version:string,filename:string,sha256:string,url:string} */
    private function resolveRelease(string $requestedVersion): array
    {
        if (preg_match('/^(8\.(?:4|5))(?:\.0)?$/', $requestedVersion, $match) === 1) {
            $branch = $match[1];
            $exactVersion = null;
        } elseif (preg_match('/^(8\.(?:4|5))\.\d+$/', $requestedVersion, $match) === 1) {
            $branch = $match[1];
            $exactVersion = $requestedVersion;
        } else {
            throw new \InvalidArgumentException(
                "Unsupported PHP source version `{$requestedVersion}`. Expected 8.4, 8.5, or a stable patch release.",
            );
        }

        $data = json_decode(
            $this->downloadText(sprintf(self::RELEASE_API, rawurlencode($branch))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid release list returned by PHP.net');
        }
        if ($exactVersion === null) {
            $versions = array_keys($data);
            usort($versions, static fn(string $left, string $right): int => version_compare($right, $left));
            $exactVersion = current(array_values(array_filter(
                $versions,
                static fn(string $version): bool => preg_match('/^' . preg_quote($branch, '/') . '\.\d+$/', $version) === 1,
            ))) ?: null;
        }
        if ($exactVersion === null || !isset($data[$exactVersion]) || !is_array($data[$exactVersion])) {
            throw new \RuntimeException("PHP {$requestedVersion} was not found in the official release list");
        }
        foreach ($data[$exactVersion]['source'] ?? [] as $source) {
            $filename = (string) ($source['filename'] ?? '');
            $sha256 = (string) ($source['sha256'] ?? '');
            if (str_ends_with($filename, '.tar.xz') && $sha256 !== '') {
                return [
                    'version' => $exactVersion,
                    'filename' => $filename,
                    'sha256' => $sha256,
                    'url' => 'https://www.php.net/distributions/' . rawurlencode($filename),
                ];
            }
        }
        throw new \RuntimeException("PHP {$exactVersion} has no verified tar.xz source archive");
    }

    /** @param array{version:string,filename:string,sha256:string,url:string} $release */
    private function prepareLocked(array $release): string
    {
        $sourceDirectory = $this->cacheDirectory . '/src';
        $this->mkdir($sourceDirectory);
        $source = $sourceDirectory . '/php-' . $release['version'];
        if ($this->isComplete($source)) {
            return $source;
        }
        $archiveDirectory = $this->cacheDirectory . '/archives';
        $this->mkdir($archiveDirectory);
        $archive = $archiveDirectory . '/' . $release['filename'];
        if (!is_file($archive) || hash_file('sha256', $archive) !== $release['sha256']) {
            ($this->output)('Downloading PHP ' . $release['version'] . ' source from php.net');
            $temporary = $archive . '.part-' . bin2hex(random_bytes(6));
            try {
                $this->downloadFile($release['url'], $temporary);
                if (hash_file('sha256', $temporary) !== $release['sha256']) {
                    throw new \RuntimeException('PHP source archive SHA-256 verification failed');
                }
                if (!rename($temporary, $archive)) {
                    throw new \RuntimeException("Unable to cache PHP source archive: {$archive}");
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        if (is_dir($source) && !$this->isComplete($source)) {
            throw new \RuntimeException("Incomplete PHP source cache must be removed: {$source}");
        }
        ($this->output)('Extracting PHP ' . $release['version'] . ' source into ' . $sourceDirectory);
        $this->run(['tar', '-xJf', $archive, '-C', $sourceDirectory]);
        if (!$this->isComplete($source)) {
            throw new \RuntimeException("PHP source archive did not produce a complete source tree: {$source}");
        }
        return $source;
    }

    private function isComplete(string $source): bool
    {
        foreach (['configure', 'main/php.h', 'main/php_version.h', 'Zend/zend.h', 'ext/standard/fsock.h'] as $file) {
            if (!is_file($source . '/' . $file)) {
                return false;
            }
        }
        return true;
    }

    public static function version(string $source): string
    {
        $header = rtrim($source, '/\\') . '/main/php_version.h';
        $contents = is_file($header) ? file_get_contents($header) : false;
        if (!is_string($contents)
            || preg_match('/^#define\s+PHP_VERSION\s+"([^"]+)"/m', $contents, $match) !== 1) {
            throw new \RuntimeException("Cannot determine PHP source version from {$header}");
        }
        return $match[1];
    }

    private function downloadText(string $url): string
    {
        $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'TypePHP/tpc']]);
        $contents = @file_get_contents($url, false, $context);
        if (is_string($contents)) {
            return $contents;
        }
        $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
        if ($curl === '') {
            throw new \RuntimeException("Unable to download {$url}; enable allow_url_fopen or install curl");
        }
        return $this->capture([$curl, '--fail', '--location', '--retry', '3', $url]);
    }

    private function downloadFile(string $url, string $target): void
    {
        $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
        if ($curl !== '') {
            $this->run([$curl, '--fail', '--location', '--retry', '3', '--output', $target, $url]);
            return;
        }
        $contents = $this->downloadText($url);
        if (file_put_contents($target, $contents) === false) {
            throw new \RuntimeException("Unable to write {$target}");
        }
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException('Command failed: ' . implode(' ', $command));
        }
    }

    /** @param list<string> $command */
    private function capture(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to run command: ' . implode(' ', $command));
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException(trim($stderr));
        }
        return $stdout;
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create PHP source cache directory: {$directory}");
        }
    }
}
