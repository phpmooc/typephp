<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\Config\ProjectYamlLoader;

final class CompilerProjectConfigurationTest extends TestCase
{
    public function testOnlyReleaseCompilerEmbedsComposerRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $loader = new ProjectYamlLoader(
            PHP_VERSION,
            static fn(string $message): never => throw new \RuntimeException($message),
        );
        $development = $loader->load($root . '/project.yml');
        $release = $loader->load($root . '/project-release.yml');

        $this->assertArrayNotHasKey('embedded-files', $development);
        $this->assertSame(['./vendor'], $release['embedded-files'] ?? null);

        unset($release['embedded-files']);
        $this->assertSame($development, $release);
    }
}
