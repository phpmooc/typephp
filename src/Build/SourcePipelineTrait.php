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
    private array $embeddedPhpFiles = [];

    /** @var list<string> All regular files selected by embedded-files. */
    private array $embeddedFiles = [];

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

    /** Embed OPcache's file-cache bytes for scripts left to ZendVM. */
    private function genEmbeddedOpcodeTable(): array
    {
        $output = $this->getBuildDir() . '/embedded-opcodes-' . $this->targetName . '.cc';
        $this->migrateEmbeddedOpcodeCaches();
        $files = array_values(array_unique($this->embeddedOpcodeFiles));
        sort($files, SORT_STRING);

        $blobs = [];
        $phpVersion = PHP_VERSION;
        if ($files !== []) {
            ['blobs' => $blobs, 'phpVersion' => $phpVersion] = $this->generateEmbeddedOpcodeBlobs($files);
        }

        $sources = $this->emitEmbeddedArchiveSources($output, $files, $blobs, $phpVersion);
        $this->removeLegacyAnonymousArtifacts();
        return $sources;
    }

    private function migrateEmbeddedOpcodeCaches(): void
    {
        foreach (glob($this->getBuildDir() . '/opcache-*') ?: [] as $legacyCache) {
            if (is_dir($legacyCache)) {
                $this->moveLegacyBuildCache(
                    $legacyCache,
                    $this->getBuildDir() . '/cache/opcache/' . basename($legacyCache),
                );
            }
        }
        $this->moveLegacyBuildCache(
            $this->getBuildDir() . '/compile-embedded-opcodes.php',
            $this->getBuildDir() . '/cache/opcache/compile-embedded-opcodes.php',
        );
    }

    /** @param list<string> $files @return array{blobs: array<string, string>, phpVersion: string} */
    private function generateEmbeddedOpcodeBlobs(array $files): array
    {
        if ($this->isIosTarget() || $this->isAndroidTarget() || $this->isWasiTarget()) {
            throw new \RuntimeException('Embedded opcodes require a native PHP build host matching the target');
        }
        $this->output('Generating embedded opcodes for ' . count($files) . ' PHP files', 'lightBlue');

        $generator = new EmbeddedOpcodeGenerator(
            $this->getBuildDir(),
            $this->targetName,
            $this->getOpcodeBuildPhpCli(),
            $this->getOpcodeBuildExtensionArgs(),
            $this->opcodeBuildPhpVersion,
            $this->opcodeBuildSignature,
            $this->climate->arguments->defined('force'),
        );
        $batch = $generator->prepare($files, $this->anonymousOpcodeKeys);
        $this->reportEmbeddedOpcodeCache($batch);

        $progress = $this->startOpcodeProgress('Opcodes', $batch->pendingCount());
        $blobs = $generator->compile(
            $batch,
            fn(string $file) => $this->climate->warning(
                'Skipping non-executable embedded PHP file: ' . $file,
            ),
            fn(int $completed, int $total, string $file) => $this->updateOpcodeProgress(
                $progress,
                'Opcodes',
                $completed,
                $total,
                $this->noProgress ? $file : null,
            ),
        );
        if ($progress !== null) {
            echo PHP_EOL;
        }
        return ['blobs' => $blobs, 'phpVersion' => $this->opcodeBuildPhpVersion];
    }

    private function reportEmbeddedOpcodeCache(EmbeddedOpcodeBatch $batch): void
    {
        if ($batch->vendorCount() !== 0) {
            $vendorHits = $batch->vendorHits();
            $vendorSkips = $batch->skippedVendorCount();
            $this->output(
                'Vendor opcode cache: ' . $vendorHits . ' reused, '
                . ($batch->vendorCount() - $vendorHits - $vendorSkips) . ' to generate'
                . ($vendorSkips === 0 ? '' : ', ' . $vendorSkips . ' skipped'),
                'lightBlue',
            );
        }
        if ($batch->anonymousCount() !== 0) {
            $anonymousHits = $batch->anonymousHits();
            $this->output(
                'Anonymous opcode cache: ' . $anonymousHits . ' reused, '
                . ($batch->anonymousCount() - $anonymousHits) . ' to generate',
                'lightBlue',
            );
        }
    }

    /**
     * @param list<string> $opcodeFiles
     * @param array<string, string> $blobs
     * @return list<string>
     */
    private function emitEmbeddedArchiveSources(
        string $output,
        array $opcodeFiles,
        array $blobs,
        string $phpVersion,
    ): array {
        $archive = $this->getBuildDir() . '/cache/embedded/embedded-files-' . $this->targetName . '.bin';
        $this->moveLegacyBuildCache(
            $this->getBuildDir() . '/embedded-files-' . $this->targetName . '.bin',
            $archive,
        );
        $sources = [$output];
        $embeddedArchive = EmbeddedArchive::empty($archive);
        if ($this->embeddedFiles !== [] || $opcodeFiles !== []) {
            $embeddedArchive = (new EmbeddedArchiveBuilder())->build(
                $archive,
                $this->embeddedFiles,
                $blobs,
                $this->anonymousOpcodeKeys,
            );
            if ($this->isWindows()) {
                $this->embeddedArchiveFile = $archive;
            } else {
                $assembly = $this->getBuildDir() . '/embedded-files-' . $this->targetName . '.S';
                $quotedArchive = json_encode($archive, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $section = $this->isMacos() ? '__TEXT,__const' : '.rodata';
                $symbol = $this->isMacos()
                    ? '_typephp_embedded_archive_start' : 'typephp_embedded_archive_start';
                $asmCode = "# archive-sha256: {$embeddedArchive->hash}\n.section {$section}\n.globl {$symbol}\n.p2align 4\n{$symbol}:\n.incbin {$quotedArchive}\n";
                $this->writeFile($assembly, $asmCode);
                $this->generatedProjectSources[$assembly] = true;
                $sources[] = $assembly;
            }
            $this->output(
                'Packed ' . count($embeddedArchive->fileIndex) . ' files and '
                    . count($embeddedArchive->opcodeIndex) . ' opcode blobs',
                'green',
            );
        }

        $this->writeFile(
            $output,
            (new EmbeddedTableRenderer())->render($embeddedArchive, $phpVersion, $this->isWindows()),
        );
        $this->generatedProjectSources[$output] = true;
        return $sources;
    }

    private function removeLegacyAnonymousArtifacts(): void
    {
        foreach (glob($this->getBuildDir() . '/anonymous-*.json') ?: [] as $legacyManifest) {
            unlink($legacyManifest);
        }
        foreach (glob($this->getBuildDir() . '/anonymous-' . $this->targetName . '-*.php') ?: [] as $legacySource) {
            unlink($legacySource);
        }
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
        if ($this->embeddedFiles !== [] && !$this->isBuildModeBin()) {
            $this->error('`embedded-files` requires `mode: bin`');
        }
        if ($this->embeddedFiles !== []) {
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
            $this->embeddedOpcodeFiles = array_values(array_diff($this->embeddedPhpFiles, $files));
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
        $this->embeddedOpcodeFiles = array_values(array_diff($this->embeddedPhpFiles, $files));
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
        $filteredFiles = [];
        $seenRealPaths = [];
        foreach ($files as $file) {
            if ($this->shouldIgnoreFile($file)) {
                continue;
            }

            // Ignore rules describe the path used to reach a source. Resolve
            // identity only after those rules have selected the surviving
            // aliases, then compile each physical file once.
            $identity = realpath($file) ?: $file;
            if (isset($seenRealPaths[$identity])) {
                continue;
            }
            $seenRealPaths[$identity] = true;
            $filteredFiles[] = $file;
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
                        $shouldRegenerate = $this->shouldRegeneratePhpFile($path);
                        $hasAnonymousManifest = is_file($anonymousManifest);
                        $needsAnonymousRefresh = !$shouldRegenerate
                            && (is_file($legacyAnonymousManifest)
                                || ($hasAnonymousManifest
                                    ? !$this->canEmbedAnonymousClassOpcode()
                                    : (preg_match('/new\s+class\b/', (string) file_get_contents($path)) === 1
                                        && $this->canEmbedAnonymousClassOpcode())));
                        if (!$shouldRegenerate && !$needsAnonymousRefresh) {
                            if ($hasAnonymousManifest) {
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
                    if (in_array($file, $this->embeddedPhpFiles, true)
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
            if ($this->isBuildModeEmbed() && !$this->isNanoMode()
                && ($this->embeddedFiles !== [] || $this->embeddedOpcodeFiles !== [])) {
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
