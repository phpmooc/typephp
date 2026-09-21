<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use Ajaxray\AnsiKit\AnsiTerminal;
use Ajaxray\AnsiKit\Components\Progressbar;
use TypePhp\Backend\CompilerFactory;
use TypePhp\Exception\SyntaxError;
use TypePhp\Exception\Unsupported;
use TypePhp\Installer\LibPhpInstaller;
use TypePhp\Installer\LibPhpxInstaller;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Wasi;
use TypePhp\Platform\Windows;

trait SourcePipelineTrait
{
    use PreparedProjectCacheTrait;

    /** @var list<string> PHP files selected by embedded-files. */
    private array $bundledPhpFiles = [];

    /** @var list<string> All regular files selected by embedded-files. */
    private array $bundledFiles = [];

    /** @var list<string> Selected PHP files not translated to native code. */
    private array $embeddedOpcodeFiles = [];

    /** @var list<string> Anonymous classes generated from the current source file. */
    private array $currentAnonymousFiles = [];

    /** @var array<string, string> Build-time PHP path to runtime anonymous-class key. */
    private array $anonymousOpcodeKeys = [];

    /** @var ?list<string> Zend extension arguments for the build PHP CLI. */
    private ?array $opcodeBuildExtensionArgs = null;

    private bool $opcodeBuildChecked = false;
    private string $opcodeBuildProbeError = '';
    private string $opcodeBuildPhpVersion = '';
    private string $opcodeBuildSignature = '';

    private ?string $embeddedArchiveFile = null;

    private function getOpcodeBuildPhpCli(): string
    {
        return $this->getPhpDir() . ($this->isWindows() ? '/php.exe' : '/bin/php');
    }

    /**
     * Prepare PHP inputs for the Composer php-nano source-composition build.
     *
     * The compiler itself still runs on Zend PHP, but generated sources do not
     * inspect or link the host libphp installation.
     *
     * @param list<string> $files
     * @return list<string>
     */
    public function prepareNanoSources(
        array $files,
        string $targetName,
        string $buildDir,
        bool $wasi,
    ): array {
        if ($files === []) {
            return [];
        }

        $this->nanoMode = true;
        $this->nanoPolicyMode = true;
        // Persistent literal wrappers are normally constructed after libphp has
        // initialized Zend. A standalone executable starts php-nano from main(),
        // so keep literals inside function scope for now.
        $this->noLiteralStrings = true;
        $this->buildMode = self::BUILD_MODE_BIN;
        $this->targetPlatform = $wasi ? 'wasm32-wasip2' : '';
        $this->setTargetName($targetName);
        $this->setBuildDir($buildDir);
        if ($this->climate->arguments->defined('force')) {
            $this->clearIncrementalBuildCache();
        }

        $resolvedFiles = [];
        foreach ($files as $file) {
            $resolved = realpath($file);
            if ($resolved === false || !is_file($resolved)
                || !FileScanner::isPhpFile($resolved)) {
                throw new \RuntimeException("Invalid TypePHP native source: {$file}");
            }
            $resolvedFiles[] = $resolved;
            $this->sourceDirs[] = dirname($resolved);
        }
        $resolvedFiles = array_values(array_unique($resolvedFiles));
        $this->sourceDirs = array_values(array_unique($this->sourceDirs));

        $this->discoverNativeClassDeclarations($resolvedFiles);
        foreach ($resolvedFiles as $key => $file) {
            try {
                $this->prepareFile($file);
            } catch (Unsupported $exception) {
                $this->output(
                    ' unsupported syntax: ' . $exception->getMessage()
                    . "\n skip: {$file}\n",
                    'error',
                );
                unset($resolvedFiles[$key]);
            } catch (SyntaxError $exception) {
                $this->output(
                    ' syntax error: ' . $exception->getMessage()
                    . "\n skip: {$file}\n",
                    'error',
                );
                unset($resolvedFiles[$key]);
            }
        }

        $resolvedFiles = array_values($resolvedFiles);
        $this->composeTraitDeclarations($resolvedFiles);
        $this->discoverNativeGlobalObjects($resolvedFiles);
        $resolvedFiles = $this->getSortedFiles($resolvedFiles);
        $this->initializeIncrementalCompilation($resolvedFiles);
        return $resolvedFiles;
    }

    public function addFiles(array $files): void
    {
        $this->sourceDirs = array_merge($this->sourceDirs, $files);
    }

    protected function embedAnonymousClassCode(string $name, string $code): string
    {
        $path = $this->getBuildDir() . '/cache/anonymous/source/anonymous-' . $this->targetName . '-'
            . substr(hash('sha256', $this->file), 0, 12) . '-' . $name . '.php';
        $this->writeFile($path, "<?php\n" . $code . "\n");
        $this->embeddedOpcodeFiles[] = $path;
        $this->anonymousOpcodeKeys[$path] = $this->anonymousOpcodeKey($path);
        $this->currentAnonymousFiles[] = $path;
        return $this->anonymousOpcodeKeys[$path];
    }

    private function anonymousOpcodeKey(string $path): string
    {
        return '@typephp:anon:' . substr(hash('sha256', $path), 0, 32);
    }

    private function anonymousManifestPath(string $source): string
    {
        return $this->getBuildDir() . '/cache/anonymous/manifests/'
            . substr(hash('sha256', $source), 0, 20) . '.json';
    }

    private function moveLegacyBuildCache(string $legacy, string $current): void
    {
        if (!file_exists($legacy)) {
            return;
        }
        $destination = $current;
        if (file_exists($destination)) {
            $base = $this->getBuildDir() . '/cache/legacy/' . basename($legacy);
            $destination = $base;
            for ($suffix = 1; file_exists($destination); ++$suffix) {
                $destination = $base . '-' . $suffix;
            }
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create build cache directory: {$directory}");
        }
        if (!rename($legacy, $destination)) {
            throw new \RuntimeException("Cannot move build cache into {$directory}: {$legacy}");
        }
    }

    private function restoreAnonymousManifest(string $source): void
    {
        $manifest = $this->anonymousManifestPath($source);
        if (!is_file($manifest)) {
            return;
        }
        $paths = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($paths)) {
            throw new \RuntimeException("Invalid anonymous class manifest: {$manifest}");
        }
        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path)) {
                throw new \RuntimeException("Missing anonymous class source: {$path}");
            }
            $this->embeddedOpcodeFiles[] = $path;
            $this->anonymousOpcodeKeys[$path] = $this->anonymousOpcodeKey($path);
        }
    }

    /** Check whether the target PHP CLI can generate OPcache file-cache blobs. */
    private function probeOpcodeBuildExtensionArgs(): ?array
    {
        if ($this->opcodeBuildChecked) {
            return $this->opcodeBuildExtensionArgs;
        }
        $this->opcodeBuildChecked = true;

        $php = $this->getOpcodeBuildPhpCli();
        if (!is_file($php) || !is_executable($php)) {
            $this->opcodeBuildProbeError = "Build PHP CLI is not executable: {$php}";
            return null;
        }

        $extensionCandidates = [[], ['-d', 'zend_extension=opcache']];
        if ($this->isWindows()) {
            $extensionCandidates[] = ['-d', 'zend_extension=' . $this->getPhpDir() . '/ext/php_opcache.dll'];
        }
        foreach ($extensionCandidates as $extensionArgs) {
            $process = proc_open(
                [
                    $php, '-n', ...$extensionArgs, '-d', 'opcache.enable_cli=1',
                    '-r', 'if (!extension_loaded("Zend OPcache") || !function_exists("opcache_compile_file")) exit(1); '
                        . '$extension = PHP_OS_FAMILY === "Windows" ? dirname(PHP_BINARY) . "/ext/php_opcache.dll" '
                        . ': ini_get("extension_dir") . DIRECTORY_SEPARATOR . "opcache." . PHP_SHLIB_SUFFIX; '
                        . 'echo json_encode(["php" => PHP_VERSION, "opcache" => phpversion("Zend OPcache"), '
                        . '"binary" => [PHP_BINARY, hash_file("sha256", PHP_BINARY)], '
                        . '"extension" => [$extension, is_file($extension) ? hash_file("sha256", $extension) : null], '
                        . '"zts" => PHP_ZTS, "debug" => PHP_DEBUG, "int_size" => PHP_INT_SIZE]);',
                ],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                $this->opcodeBuildProbeError = "Cannot start the build PHP CLI: {$php}";
                return null;
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) === 0) {
                $signature = json_decode((string) $stdout, true);
                if (!is_array($signature) || !is_string($signature['php'] ?? null)) {
                    throw new \RuntimeException("Invalid OPcache build PHP signature from {$php}");
                }
                $this->opcodeBuildPhpVersion = $signature['php'];
                $this->opcodeBuildSignature = (string) $stdout;
                return $this->opcodeBuildExtensionArgs = $extensionArgs;
            }
            if ($stderr !== false && trim($stderr) !== '') {
                $this->opcodeBuildProbeError = trim($stderr);
            }
        }
        return null;
    }

    private function getOpcodeBuildExtensionArgs(): array
    {
        $extensionArgs = $this->probeOpcodeBuildExtensionArgs();
        if ($extensionArgs !== null) {
            return $extensionArgs;
        }
        $php = $this->getOpcodeBuildPhpCli();
        $detail = $this->opcodeBuildProbeError === '' ? '' : "\n" . $this->opcodeBuildProbeError;
        $this->error("Embedded opcode generation requires Zend OPcache for the build PHP CLI: {$php}{$detail}");
    }

    protected function canEmbedAnonymousClassOpcode(): bool
    {
        return $this->isBuildModeBin() && !$this->isNanoMode()
            && !$this->isIosTarget() && !$this->isAndroidTarget() && !$this->isWasiTarget()
            && $this->probeOpcodeBuildExtensionArgs() !== null;
    }

    private function startOpcodeProgress(string $label, int $total): ?Progressbar
    {
        if ($this->noProgress || $total === 0) {
            return null;
        }
        $progress = new Progressbar();
        $progress->barStyle([AnsiTerminal::FG_GREEN])
            ->percentageStyle([AnsiTerminal::TEXT_BOLD])
            ->labelStyle([AnsiTerminal::FG_CYAN]);
        $progress->renderInPlace(0, $total, $label);
        return $progress;
    }

    private function updateOpcodeProgress(
        ?Progressbar $progress,
        string $label,
        int $completed,
        int $total,
        ?string $file = null,
    ): void {
        if ($progress !== null) {
            $progress->renderInPlace($completed, $total, $label);
        } elseif ($this->noProgress && ($file !== null || $completed % 100 === 0 || $completed === $total)) {
            $percent = (int) ceil($completed / $total * 100);
            $detail = $file === null ? '' : " {$file}";
            $this->output("[{$completed}/{$total}] {$percent}% {$label}{$detail}", 'white');
        }
    }

    /** Only files under a vendor directory with autoload.php use the mtime cache. */
    private function vendorRootForOpcodeFile(string $file): ?string
    {
        for ($directory = dirname($file); $directory !== dirname($directory); $directory = dirname($directory)) {
            if (basename($directory) === 'vendor' && is_file($directory . '/autoload.php')) {
                return $directory;
            }
        }
        return null;
    }

    private function findOpcodeBlob(string $cacheDir, string $file): ?string
    {
        $matches = [];
        foreach (glob($cacheDir . '/*', GLOB_ONLYDIR) ?: [] as $systemDirectory) {
            $candidate = $systemDirectory . $file . '.bin';
            if (is_file($candidate) && filesize($candidate) > 0) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Ambiguous OPcache blob for {$file}");
        }
        if ($matches !== []) {
            return $matches[0];
        }
        // OPcache uses a different file-cache path layout on Windows.
        if (DIRECTORY_SEPARATOR === '\\' && is_dir($cacheDir)) {
            // The drive colon is omitted from OPcache's cache path (C:\foo
            // becomes C\foo), after two system-specific cache directories.
            $windowsCacheSuffix = str_replace('/', '\\', preg_replace('/^([A-Za-z]):/', '$1', $file)) . '.bin';
            foreach (glob($cacheDir . '/*', GLOB_ONLYDIR) ?: [] as $systemDirectory) {
                foreach (glob($systemDirectory . '/*', GLOB_ONLYDIR) ?: [] as $subDirectory) {
                    $candidate = $subDirectory . DIRECTORY_SEPARATOR . $windowsCacheSuffix;
                    if (is_file($candidate) && filesize($candidate) > 0) {
                        $matches[] = $candidate;
                    }
                }
            }
            if (count($matches) > 1) {
                throw new \RuntimeException("Ambiguous OPcache blob for {$file}");
            }
            if ($matches !== []) {
                return $matches[0];
            }
            // Keep supporting other OPcache layouts without assuming the same
            // number of system directories in every PHP build.
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $cached) {
                if ($cached->isFile() && $cached->getSize() > 0
                    && str_ends_with($cached->getPathname(), $windowsCacheSuffix)) {
                    if ($matches !== []) {
                        throw new \RuntimeException("Ambiguous OPcache blob for {$file}");
                    }
                    $matches[] = $cached->getPathname();
                }
            }
        }
        return $matches[0] ?? null;
    }

    private function getSkippedOpcodeMarker(string $cacheDir, string $file): string
    {
        return $cacheDir . '/skipped-' . hash('sha256', $file) . '.txt';
    }

    private function clearOpcodeCacheDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() ? !rmdir($path) : !unlink($path)) {
                throw new \RuntimeException("Cannot clear opcode build directory: {$path}");
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException("Cannot clear opcode build directory: {$directory}");
        }
    }

    /** Embed OPcache's file-cache bytes for scripts left to ZendVM. */
    private function genEmbeddedOpcodeTable(): array
    {
        $output = $this->getBuildDir() . '/embedded-opcodes-' . $this->targetName . '.cc';
        foreach (glob($this->getBuildDir() . '/opcache-*') ?: [] as $legacyCache) {
            if (is_dir($legacyCache)) {
                $this->moveLegacyBuildCache(
                    $legacyCache,
                    $this->getBuildDir() . '/cache/opcache/' . basename($legacyCache),
                );
            }
        }
        $files = array_values(array_unique($this->embeddedOpcodeFiles));
        sort($files, SORT_STRING);
        $blobs = [];
        $phpVersion = PHP_VERSION;

        if ($files !== []) {
            if ($this->isIosTarget() || $this->isAndroidTarget() || $this->isWasiTarget()) {
                throw new \RuntimeException('Embedded opcodes require a native PHP build host matching the target');
            }
            $this->output('Generating embedded opcodes for ' . count($files) . ' PHP files', 'lightBlue');
            $php = $this->getOpcodeBuildPhpCli();
            $extensionArgs = $this->getOpcodeBuildExtensionArgs();
            $phpVersion = $this->opcodeBuildPhpVersion;
            $otherFiles = [];
            $vendorRoots = [];
            $vendorFiles = [];
            $anonymousFiles = [];
            $fileCacheDirs = [];
            foreach ($files as $file) {
                if (isset($this->anonymousOpcodeKeys[$file])) {
                    // These files are emitted by this compiler, so their exact
                    // contents provide a safe cache key independent of any
                    // user-supplied directory timestamp.
                    $key = hash('sha256', implode("\n", [
                        'anonymous-opcodes-v1', $file,
                        hash_file('sha256', $file), $this->opcodeBuildSignature,
                    ]));
                    $cacheDir = $this->getBuildDir() . '/cache/opcache/anonymous-' . substr($key, 0, 20);
                    if ($this->climate->arguments->defined('force')) {
                        $this->clearOpcodeCacheDirectory($cacheDir);
                    }
                    $fileCacheDirs[$file] = $cacheDir;
                    $anonymousFiles[$file] = true;
                    continue;
                }
                $vendorRoot = $this->vendorRootForOpcodeFile($file);
                if ($vendorRoot === null) {
                    $otherFiles[] = $file;
                    continue;
                }
                $vendorRoots[$vendorRoot][] = $file;
                $vendorFiles[$file] = true;
            }
            foreach ($vendorRoots as $vendorRoot => $filesInVendor) {
                clearstatcache(true, $vendorRoot);
                $mtime = filemtime($vendorRoot);
                if ($mtime === false) {
                    throw new \RuntimeException("Cannot read vendor directory mtime: {$vendorRoot}");
                }
                $key = hash('sha256', implode("\n", [
                    'vendor-directory-mtime-opcodes-v2', $vendorRoot,
                    (string) $mtime, $this->opcodeBuildSignature,
                ]));
                $cacheDir = $this->getBuildDir() . '/cache/opcache/vendor-' . substr($key, 0, 20);
                if ($this->climate->arguments->defined('force')) {
                    $this->clearOpcodeCacheDirectory($cacheDir);
                }
                foreach ($filesInVendor as $file) {
                    $fileCacheDirs[$file] = $cacheDir;
                }
            }
            if ($otherFiles !== []) {
                // Recreate this staging directory on every build so OPcache
                // cannot reuse bytecode for non-vendor or anonymous sources.
                $cacheDir = $this->getBuildDir() . '/cache/opcache/nonvendor-'
                    . substr(hash('sha256', $this->targetName), 0, 20);
                $this->clearOpcodeCacheDirectory($cacheDir);
                foreach ($otherFiles as $file) {
                    $fileCacheDirs[$file] = $cacheDir;
                }
            }
            $driver = $this->getBuildDir() . '/cache/opcache/compile-embedded-opcodes.php';
            $this->moveLegacyBuildCache(
                $this->getBuildDir() . '/compile-embedded-opcodes.php',
                $driver,
            );
            $this->writeFile($driver, <<<'PHP'
<?php
echo PHP_VERSION, "\n";
foreach (array_slice($argv, 1) as $path) {
    if (!opcache_compile_file($path)) {
        fwrite(STDERR, "OPcache could not compile: {$path}\n");
        exit(1);
    }
}
PHP
            );
            $command = [
                $php, '-n', ...$extensionArgs,
                '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.file_cache_only=1',
                '-d', 'opcache.file_update_protection=0',
            ];
            // OPcache installs declarations in the compiling CLI process.
            // Isolate files so unrelated scripts with the same global
            // function names do not conflict while creating their bytecode.
            $pending = [];
            $vendorHits = 0;
            $anonymousHits = 0;
            $vendorSkips = 0;
            foreach ($files as $file) {
                $cacheDir = $fileCacheDirs[$file];
                if (isset($vendorFiles[$file])
                    && is_file($this->getSkippedOpcodeMarker($cacheDir, $file))) {
                    ++$vendorSkips;
                    continue;
                }
                if ((isset($vendorFiles[$file]) || isset($anonymousFiles[$file]))
                    && ($blob = $this->findOpcodeBlob($cacheDir, $file)) !== null) {
                    $blobs[$file] = $blob;
                    if (isset($anonymousFiles[$file])) {
                        ++$anonymousHits;
                    } else {
                        ++$vendorHits;
                    }
                } else {
                    $pending[] = $file;
                }
            }
            if ($vendorRoots !== []) {
                $vendorCount = array_sum(array_map('count', $vendorRoots));
                $this->output(
                    'Vendor opcode cache: ' . $vendorHits . ' reused, '
                    . ($vendorCount - $vendorHits - $vendorSkips) . ' to generate'
                    . ($vendorSkips === 0 ? '' : ', ' . $vendorSkips . ' skipped'),
                    'lightBlue',
                );
            }
            if ($anonymousFiles !== []) {
                $this->output(
                    'Anonymous opcode cache: ' . $anonymousHits . ' reused, '
                    . (count($anonymousFiles) - $anonymousHits) . ' to generate',
                    'lightBlue',
                );
            }
            $progress = $this->startOpcodeProgress('Opcodes', count($pending));
            $completed = 0;
            foreach ($pending as $file) {
                $cacheDir = $fileCacheDirs[$file];
                if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
                    throw new \RuntimeException("Cannot create OPcache build directory: {$cacheDir}");
                }
                $process = proc_open([...$command, '-d', 'opcache.file_cache=' . $cacheDir, $driver, $file], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                if (!is_resource($process)) {
                    throw new \RuntimeException('Cannot start the PHP OPcache build process');
                }
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($process) !== 0) {
                    if (isset($anonymousFiles[$file])
                        || !str_contains((string) $stderr, "OPcache could not compile: {$file}")) {
                        throw new \RuntimeException("Cannot compile embedded opcode {$file}:\n{$stderr}");
                    }
                    // Embedded directories can contain PHP-looking declaration
                    // files that are not scripts. Keep their raw bytes but do
                    // not treat them as executable bytecode.
                    $this->climate->warning('Skipping non-executable embedded PHP file: ' . $file);
                    if (isset($vendorFiles[$file])) {
                        $marker = $this->getSkippedOpcodeMarker($cacheDir, $file);
                        if (file_put_contents($marker, $file . PHP_EOL) === false) {
                            throw new \RuntimeException("Cannot cache skipped embedded PHP file: {$file}");
                        }
                    }
                    $this->updateOpcodeProgress($progress, 'Opcodes', ++$completed, count($pending), $this->noProgress ? $file : null);
                    continue;
                }
                // Compiled files may emit PHP deprecation notices on stdout.
                // The driver prints its version before compiling the file.
                if (strtok((string) $stdout, "\r\n") !== $phpVersion) {
                    throw new \RuntimeException("Build PHP version changed while compiling {$file}");
                }
                $blob = $this->findOpcodeBlob($cacheDir, $file);
                if ($blob === null) {
                    throw new \RuntimeException("Missing OPcache blob for {$file}");
                }
                $blobs[$file] = $blob;
                $this->updateOpcodeProgress($progress, 'Opcodes', ++$completed, count($pending), $this->noProgress ? $file : null);
            }
            if ($progress !== null) {
                echo PHP_EOL;
            }
            uksort($blobs, static fn(string $left, string $right): int => strcmp($left, $right));
        }

        $archive = $this->getBuildDir() . '/cache/embedded/embedded-files-' . $this->targetName . '.bin';
        $this->moveLegacyBuildCache(
            $this->getBuildDir() . '/embedded-files-' . $this->targetName . '.bin',
            $archive,
        );
        $rawIndex = [];
        $opcodeIndex = [];
        $sources = [$output];
        if ($this->bundledFiles !== [] || $files !== []) {
            $temporaryArchive = $archive . '.tmp';
            if (!is_dir(dirname($archive))
                && !mkdir(dirname($archive), 0777, true) && !is_dir(dirname($archive))) {
                throw new \RuntimeException('Cannot create embedded archive directory: ' . dirname($archive));
            }
            $stream = fopen($temporaryArchive, 'wb');
            if ($stream === false) {
                throw new \RuntimeException("Cannot create embedded file archive: {$temporaryArchive}");
            }
            foreach ($this->bundledFiles as $file) {
                $offset = ftell($stream);
                $input = fopen($file, 'rb');
                if ($input === false) {
                    throw new \RuntimeException("Cannot read embedded file: {$file}");
                }
                $length = stream_copy_to_stream($input, $stream);
                fclose($input);
                if ($length === false) {
                    throw new \RuntimeException("Cannot copy embedded file: {$file}");
                }
                $rawIndex[$file] = [$offset, $length];
            }
            foreach ($blobs as $file => $blobFile) {
                $offset = ftell($stream);
                $input = fopen($blobFile, 'rb');
                if ($input === false) {
                    throw new \RuntimeException("Cannot read opcode blob: {$blobFile}");
                }
                $length = stream_copy_to_stream($input, $stream);
                fclose($input);
                if ($length === false) {
                    throw new \RuntimeException("Cannot copy opcode blob: {$blobFile}");
                }
                $opcodeIndex[$this->anonymousOpcodeKeys[$file] ?? $file] = [$offset, $length];
            }
            fclose($stream);
            if (!is_file($archive) || hash_file('sha256', $archive) !== hash_file('sha256', $temporaryArchive)) {
                rename($temporaryArchive, $archive);
            } else {
                unlink($temporaryArchive);
            }
            $archiveHash = hash_file('sha256', $archive);
            if ($this->isWindows()) {
                $this->embeddedArchiveFile = $archive;
            } else {
                $assembly = $this->getBuildDir() . '/embedded-files-' . $this->targetName . '.S';
                $quotedArchive = json_encode($archive, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $section = $this->isMacos() ? '__TEXT,__const' : '.rodata';
                $symbol = $this->isMacos()
                    ? '_typephp_embedded_archive_start' : 'typephp_embedded_archive_start';
                $asmCode = "# archive-sha256: {$archiveHash}\n.section {$section}\n.globl {$symbol}\n.p2align 4\n{$symbol}:\n.incbin {$quotedArchive}\n";
                $this->writeFile($assembly, $asmCode);
                $this->generatedProjectSources[$assembly] = true;
                $sources[] = $assembly;
            }
            $this->output(
                'Packed ' . count($rawIndex) . ' files and ' . count($opcodeIndex) . ' opcode blobs',
                'green',
            );
        }

        $code = '#include <typephp_opcode_table.h>' . PHP_EOL;
        $hasArchive = $this->bundledFiles !== [] || $files !== [];
        if ($hasArchive && $this->isWindows()) {
            $code .= 'static const uint8_t *const typephp_embedded_archive_start = typephp_embedded_archive_data();' . PHP_EOL;
        } elseif ($hasArchive) {
            $code .= 'extern "C" const uint8_t typephp_embedded_archive_start[];' . PHP_EOL;
        }
        $code .= 'static const typephp_opcode_entry typephp_opcodes[] = {' . PHP_EOL;
        foreach ($opcodeIndex as $file => [$offset, $length]) {
            $path = json_encode($file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $code .= "    {{$path}, typephp_embedded_archive_start + {$offset}, {$length}}," . PHP_EOL;
        }
        $code .= '    {nullptr, nullptr, 0},' . PHP_EOL . '};' . PHP_EOL;
        $code .= 'extern "C" const typephp_opcode_entry *typephp_project_opcode_table(size_t *count) {' . PHP_EOL;
        $code .= '    *count = ' . count($opcodeIndex) . '; return typephp_opcodes;' . PHP_EOL . '}' . PHP_EOL;
        $code .= 'static const typephp_embedded_file_entry typephp_embedded_files[] = {' . PHP_EOL;
        foreach ($rawIndex as $file => [$offset, $length]) {
            $path = json_encode($file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $code .= "    {{$path}, typephp_embedded_archive_start + {$offset}, {$length}}," . PHP_EOL;
        }
        $code .= '    {nullptr, nullptr, 0},' . PHP_EOL . '};' . PHP_EOL;
        $code .= 'extern "C" const typephp_embedded_file_entry *typephp_project_embedded_file_table(size_t *count) {' . PHP_EOL;
        $code .= '    *count = ' . count($rawIndex) . '; return typephp_embedded_files;' . PHP_EOL . '}' . PHP_EOL;
        $version = json_encode($phpVersion, JSON_THROW_ON_ERROR);
        $code .= 'extern "C" const char *typephp_project_php_version(void) { return ' . $version . '; }' . PHP_EOL;
        if ($rawIndex === [] && $opcodeIndex === []) {
            $code .= 'extern "C" void typephp_opcode_table_install(void) {}' . PHP_EOL;
            $code .= 'extern "C" void typephp_opcode_table_uninstall(void) {}' . PHP_EOL;
        }
        $this->writeFile($output, $code);
        $this->generatedProjectSources[$output] = true;
        foreach (glob($this->getBuildDir() . '/anonymous-*.json') ?: [] as $legacyManifest) {
            unlink($legacyManifest);
        }
        foreach (glob($this->getBuildDir() . '/anonymous-' . $this->targetName . '-*.php') ?: [] as $legacySource) {
            unlink($legacySource);
        }
        return $sources;
    }

    public function getFiles(string $path): array
    {
        $this->applyPhpVersionCommandLineArgument();
        $realpath = realpath($path);
        if ($realpath === false) {
            $this->error("path not exists: {$path}");
        }
        $path = $realpath;

        if (is_dir($path)) {
            // Directory mode: no YAML parsing
            $list = $this->getFilesFromDir($path);
            $targetName = basename($path);
            $this->setTargetName($targetName);
            $this->sourceDirs[] = $path;
        } else {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'yml' || $ext === 'yaml') {
                // YAML config mode: parse the YAML first
                $list = $this->parseProjectYaml($path);
            } elseif ($ext === 'php') {
                // Single-file mode: no YAML parsing
                $list = [$path];
                $targetName = FileScanner::getFileName($path);
                $this->setTargetName($targetName);
                $this->sourceDirs[] = dirname($path);
            } else {
                $this->error('Unsupported file type: ' . $path);
            }
        }

        // Apply command-line arguments after all configuration is loaded (so they
        // take the highest precedence)
        $this->applyCommandLineArguments();
        if ($this->bundledFiles !== [] && !$this->isBuildModeBin()) {
            $this->error('`embedded-files` requires `mode: bin`');
        }
        if ($this->bundledFiles !== []) {
            $this->getOpcodeBuildExtensionArgs();
        }
        if ($this->climate->arguments->defined('force')) {
            $this->clearIncrementalBuildCache();
        }
        $this->validateProjectObjectFiles();

        // The generated public import stub is an output artifact, not an input
        // of the library that produced it. Exclude a previous build's copy when
        // a project scans its output directory recursively.
        if ($this->isBuildModeLib()) {
            $generatedStub = realpath($this->getLibraryImportStubFile());
            if ($generatedStub !== false) {
                $list = array_values(array_filter(
                    $list,
                    static fn(string $file): bool => realpath($file) !== $generatedStub,
                ));
            }
        }

        return $this->filterIgnoredFiles($list);
    }

    public function prepare(string $path): array
    {
        $files = $this->getFiles($path);

        // Source-composed Nano does not consume the host PHP/PHPX runtime.
        // Windows Nano deliberately leaves nanoMode=false and therefore keeps
        // this original DLL/import-library validation path.
        if (!$this->isNanoMode()) {
            if ($this->isBuildModeEmbed() && $this->getPlatform() instanceof Linux) {
                try {
                    $phpDir = (new LibPhpInstaller())->ensure($this->getPhpDir()) ?? $this->getPhpDir();
                } catch (\Throwable $e) {
                    $this->error('Unable to install libphp.so: ' . $e->getMessage());
                }
            } else {
                $phpDir = $this->getPhpDir();
            }

            if (!($this->getPlatform() instanceof Wasi)) {
                $this->validatePhpRuntimeMinimum($phpDir);
            }

            if ($this->getPlatform() instanceof Linux) {
                try {
                    (new LibPhpxInstaller())->ensure($this->getPhpxDir(), $phpDir);
                } catch (\Throwable $e) {
                    $this->error('Unable to build libphpx.so: ' . $e->getMessage());
                }
            }

            // Pre-check the phpx library only at the PHP script entry (bin/tpc.php):
            // a missing library fails immediately rather than surfacing later during
            // file processing/compilation. The compiled tpc executable has libphpx
            // loaded by the dynamic linker before entering main(), so checking here
            // is neither needed nor possible.
            if (defined('TYPEPHP_PHP_SCRIPT_ENTRY') && !($this->getPlatform() instanceof Wasi)) {
                $this->validatePhpxLibrary();
            }
        }

        $this->validateCompilerToolchain();

        // shell_exec and define are already called directly via php::fn::, so no
        // dynamic symbol table is needed

        // All Windows build modes depend on the PHPX import library and runtime.
        // Other platforms only run the existing checks in embedded build mode.
        if (!$this->isNanoMode()
            && ($this->isBuildModeEmbed() || $this->getPlatform() instanceof Windows)) {
            foreach ($this->getPlatform()->getBuildLibraryWarnings(
                $this->getPhpDir(),
                $this->getPhpxDir(),
                $this->buildMode,
                defined('TYPEPHP_PHP_SCRIPT_ENTRY'),
            ) as $message) {
                if (!empty($message['error'])) {
                    $detail = $message['error'];
                    if (!empty($message['info'])) {
                        $detail .= "\n" . $message['info'];
                    }
                    $this->error($detail);
                }
                $this->climate->warning($message['warning']);
                if (!empty($message['info'])) {
                    $this->climate->info($message['info']);
                }
            }
        }

        $files = $this->filterIgnoredFiles($files);
        $preparedKey = $this->preparedProjectKey($files);
        if ($this->restorePreparedProject($preparedKey)) {
            $this->embeddedOpcodeFiles = array_values(array_diff($this->bundledPhpFiles, $files));
            $files = $this->getSortedFiles($files);
            $this->initializeIncrementalCompilation($files);
            return $files;
        }
        $warningsBefore = $this->preprocessingWarningCount;
        $inputCount = count($files);
        $this->discoverNativeClassDeclarations($files);
        // Analyze and preprocess the PHP files
        foreach ($files as $k => $file) {
            if (FileScanner::isPhpFile($file)) {
                try {
                    $this->prepareFile($file);
                } catch (Unsupported $e) {
                    $this->output(' unsupported syntax: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                } catch (SyntaxError $e) {
                    $this->output(' syntax error: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                }
            }
        }
        // Trait declarations can only be flattened after the complete source
        // set has been prepared: a consuming class may precede its Trait file.
        // Complete the declaration graph before any body is converted.
        $this->composeTraitDeclarations(array_values($files));
        // Global slots are shared by every translation unit. Fix any Native
        // pointer ABI now, after declarations are known and before the first
        // per-file C++ body is generated.
        $this->discoverNativeGlobalObjects(array_values($files));
        if (count($files) === $inputCount && $this->preprocessingWarningCount === $warningsBefore) {
            $this->storePreparedProject($preparedKey);
        }
        $files = $this->getSortedFiles($files);
        $this->embeddedOpcodeFiles = array_values(array_diff($this->bundledPhpFiles, $files));
        $this->initializeIncrementalCompilation($files);
        return $files;
    }

    protected function validateCompilerToolchain(): void
    {
        $backend = $this->getCompilerBackend();
        $compilerCommand = $backend->getCompilerCommand();
        if (!CompilerFactory::isCommandExecutable($compilerCommand)) {
            $program = CompilerFactory::getCommandProgram($compilerCommand);
            $this->error(
                "C/C++ compiler executable not found: {$program}\n" .
                "Configured compiler command: {$compilerCommand}\n" .
                "Install a supported compiler, or select one with --compiler, or set `cpp-compiler` in project.yml."
            );
        }

        $linkerCommand = $backend->getLinkerCommand();
        if ($linkerCommand !== $compilerCommand && !CompilerFactory::isCommandExecutable($linkerCommand)) {
            $program = CompilerFactory::getCommandProgram($linkerCommand);
            $this->error(
                "Linker executable not found: {$program}\n" .
                "Configured linker command: {$linkerCommand}\n" .
                "Install the required linker or update compiler configuration."
            );
        }
    }

    /** Validate the selected headers/libphp independently of --php-version. */
    protected function validatePhpRuntimeMinimum(string $phpDir): void
    {
        $versionId = null;
        $headers = [
            $phpDir . '/include/php/main/php_version.h',
            $phpDir . '/include/main/php_version.h',
        ];
        foreach ($headers as $header) {
            if (!is_file($header)) {
                continue;
            }
            $contents = file_get_contents($header);
            if (is_string($contents) && preg_match('/^#define\s+PHP_VERSION_ID\s+(\d+)/m', $contents, $matches)) {
                $versionId = (int) $matches[1];
                break;
            }
        }

        if ($versionId === null) {
            $phpConfig = $phpDir . '/bin/php-config';
            if (is_executable($phpConfig)) {
                $value = shell_exec(escapeshellarg($phpConfig) . ' --vernum 2>/dev/null');
                if (is_string($value) && ctype_digit(trim($value))) {
                    $versionId = (int) trim($value);
                }
            }
        }

        if ($versionId !== null && $versionId < 80400) {
            $version = intdiv($versionId, 10000) . '.' . intdiv($versionId % 10000, 100);
            $this->error("TypePHP requires libphp 8.4 or later; selected PHP installation is {$version}: {$phpDir}");
        }
    }

    protected function shouldIgnoreFile(string $file): bool
    {
        foreach ($this->ignorePaths as $ignorePath) {
            if ($file === $ignorePath) {
                return true;
            }
            if (is_dir($ignorePath) && str_starts_with($file, rtrim($ignorePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function filterIgnoredFiles(array $files): array
    {
        if (empty($this->ignorePaths)) {
            return $files;
        }

        $filteredFiles = [];
        foreach ($files as $file) {
            if (!$this->shouldIgnoreFile($file)) {
                $filteredFiles[] = $file;
            }
        }

        return $filteredFiles;
    }

    public function convert(array $files): array
    {
        $this->compilationStatistics->begin();
        $previousSplitSetting = $this->splitTranslationUnitsEnabled;
        $this->splitTranslationUnitsEnabled = true;
        $previousPhase = null;
        try {
            $this->composeTraitDeclarations($files);
            $previousPhase = $this->enterCompilerPhase(self::PHASE_CONVERT);
            // Hydrate persistent literal/resource IDs before any unchanged
            // translation unit or declaration header is reused.
            $this->getStableIdRegistry();
            // All declarations are now known. Lower declaration constant
            // expressions before translating any function body so cache IDs
            // are assigned exclusively in the convert phase.
            $this->finalizeDeclarationExpressions($this->getDeclarationInputFiles($files));
            // Whole-program extension generation must not depend on conversion
            // side effects from dirty files. Clean incremental files are not
            // converted, but their non-empty property defaults still require a
            // custom allocation path in the regenerated module entry.
            $this->finalizeRequestArrayDefaultMetadata();
            $this->initializeDeclarationHeaderFiles($files);
            $this->restoreCleanIncrementalMetadata($files);

            // Native/import stubs are declaration inputs, not ordinary PHP
            // bodies. Their Zend metadata still belongs to the module entry.
            foreach ($this->getDeclarationInputFiles($files) as $file) {
                if ($this->isStubFile($file) && $this->shouldRegeneratePhpFile($file)) {
                    $this->genStubFile($file);
                }
            }

            $sourceFiles = [];
            $validSourceCount = 0;
            // Generate the C++ files
            foreach ($files as $k => $file) {
                try {
                    if (FileScanner::isPhpFile($file)) {
                        $path = realpath($file) ?: $file;
                        $anonymousManifest = $this->anonymousManifestPath($path);
                        $legacyAnonymousManifest = $this->getBuildDir() . '/anonymous-'
                            . substr(hash('sha256', $path), 0, 20) . '.json';
                        if (is_file($legacyAnonymousManifest)
                            && file_get_contents($legacyAnonymousManifest) === '[]') {
                            unlink($legacyAnonymousManifest);
                        }
                        // Older builds wrote [] for every PHP file. Those files
                        // carry no cache data and can be removed on a cache hit.
                        if (is_file($anonymousManifest)
                            && file_get_contents($anonymousManifest) === '[]') {
                            unlink($anonymousManifest);
                        }
                        $needsAnonymousRefresh = is_file($legacyAnonymousManifest)
                            || ($this->canEmbedAnonymousClassOpcode()
                                && !is_file($anonymousManifest)
                                && preg_match('/new\s+class\b/', (string) file_get_contents($path)) === 1);
                        if (!$this->shouldRegeneratePhpFile($path) && !$needsAnonymousRefresh) {
                            if ($this->canEmbedAnonymousClassOpcode()) {
                                $this->restoreAnonymousManifest($path);
                            }
                            $validSourceCount++;
                            if ($this->incrementalTranslationUnitWasEmitted($path)) {
                                $cppFile = $this->getCppFile($path);
                                $this->registerGeneratedProjectSource($cppFile);
                                $sourceFiles[] = $cppFile;
                                foreach ($this->getSplitTranslationUnits($path) as $part) {
                                    $this->registerGeneratedProjectSource($part);
                                    $sourceFiles[] = $part;
                                }
                            }
                            $this->climate->darkGray(
                                '[cached] ' . $this->getRelativePath($path),
                            );
                            continue;
                        }
                        $statisticsBefore = $this->compilationStatistics->all();
                        $this->currentAnonymousFiles = [];
                        // A dirty dependency means code generation must run;
                        // it does not mean the generated bytes changed. Keep
                        // an existing translation unit's timestamp when the
                        // output is identical, matching CMake/Ninja's restat
                        // model and preventing needless native recompilation.
                        $cppFile = $this->convertFile($path);
                        if ($this->currentAnonymousFiles !== []) {
                            $this->writeFile($anonymousManifest, json_encode(
                                array_values(array_unique($this->currentAnonymousFiles)),
                                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                            ));
                        } elseif (is_file($anonymousManifest)) {
                            unlink($anonymousManifest);
                        }
                        if (is_file($legacyAnonymousManifest)) {
                            unlink($legacyAnonymousManifest);
                        }
                        $this->recordIncrementalConversion(
                            $path,
                            $cppFile !== null,
                            $this->compilationStatistics->delta($statisticsBefore),
                        );
                    } elseif (FileScanner::isNativeSourceFile($file)) {
                        $cppFile = $file;
                    } else {
                        continue;
                    }
                    $validSourceCount++;
                    if ($cppFile !== null) {
                        $sourceFiles[] = $cppFile;
                        if (FileScanner::isPhpFile($file)) {
                            foreach ($this->getSplitTranslationUnits($file) as $part) {
                                $this->registerGeneratedProjectSource($part);
                                $sourceFiles[] = $part;
                            }
                        }
                    }
                } catch (Unsupported $e) {
                    echo ' unsupported syntax: ' . $e->getMessage() . "\n";
                    echo ' skip: ' . $file . "\n";
                    if (in_array($file, $this->bundledPhpFiles, true)
                        && !in_array($file, $this->embeddedOpcodeFiles, true)) {
                        $this->embeddedOpcodeFiles[] = $file;
                    }
                    unset($files[$k]);
                }
            }
            $this->finalizeIncrementalConversionMetadata($files);

            // A valid PHP input may intentionally emit no standalone translation
            // unit (for example a compile-time trait or an interface). The shared
            // extension source still carries its runtime metadata, so only reject
            // an input set in which no supported source was converted at all.
            if ($validSourceCount === 0) {
                $this->stop('No valid source file found');
            }

            // A WASI library publishes WIT/Component exports rather than a native
            // TypePHP shared-library ABI, so a PHP import stub would be misleading.
            if ($this->isBuildModeLib() && !$this->isWasiTarget()) {
                $this->genLibraryImportStub($files);
            }

            // Function and data declarations are emitted together, one header
            // per PHP source, plus a small project-runtime ABI header.
            $this->genDeclarationHeaders($files);
            // Large array-valued class constants used to make module_init() one
            // enormous GCC optimization unit. Emit their request-lifecycle
            // helpers as independent, cacheable translation units while the
            // arginfo-backed class registration remains in extension-*.cc.
            foreach ($this->genClassArrayConstantLifecycleSources() as $lifecycleSource) {
                $sourceFiles[] = $lifecycleSource;
            }
            // Nano keeps the ordinary statically registered Zend class/module
            // metadata, then adds a direct native process entry beside it.
            $sourceFiles[] = $this->genExtension();
            if ($this->isBuildModeBin() && !$this->isNanoMode()) {
                array_push($sourceFiles, ...$this->genEmbeddedOpcodeTable());
            }
            if ($this->isNanoMode()) {
                $sourceFiles[] = $this->genNanoEntrypoint();
            }
            $this->getStableIdRegistry()->flush();
            $this->saveIncrementalCompilationState($files);

            return $sourceFiles;
        } finally {
            $this->splitTranslationUnitsEnabled = $previousSplitSetting;
            if ($previousPhase !== null) {
                $this->restoreCompilerPhase($previousPhase);
            }
            $this->compilationStatistics->finish();
        }
    }
}
