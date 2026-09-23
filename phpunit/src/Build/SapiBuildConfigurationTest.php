<?php

namespace TypePhpTest\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\Build\SapiBuildConfiguration;

final class SapiBuildConfigurationTest extends TestCase
{
    public function testTargetsAcceptSingleBothAndLists(): void
    {
        self::assertSame(['cli'], SapiBuildConfiguration::parseTargets('cli'));
        self::assertSame(['cli', 'fpm'], SapiBuildConfiguration::parseTargets('both'));
        self::assertSame(['fpm', 'cli'], SapiBuildConfiguration::parseTargets(['fpm', 'cli', 'fpm']));
        self::assertSame(['cli', 'fpm'], SapiBuildConfiguration::parseTargets('cli, fpm'));
    }

    public function testInvalidTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected cli, fpm, or both');
        SapiBuildConfiguration::parseTargets('apache');
    }
}
