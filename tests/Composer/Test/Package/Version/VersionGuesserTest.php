<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Package\Version;

use Composer\Config;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;

class VersionGuesserTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }
    }

    public function testDetachedHeadBecomesDevHash()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->with('git describe --exact-match --tags')
            ->willReturn(1)
        ;

        $self = $this;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash) {
                $self->assertEquals('git branch --no-color --no-abbrev -v', $command);
                $output = "* (no branch) $commitHash Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $version = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $version);
    }

    public function testTagBecomesVersion()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git describe --exact-match --tags', $command);
                $output = "v2.0.5-alpha2";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $version = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("2.0.5.0-alpha2", $version);
    }

    public function testInvalidTagBecomesVersion()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git describe --exact-match --tags', $command);
                $output = "foo-bar";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch --no-color --no-abbrev -v', $command);
                $output = "* foo 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $version = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-foo", $version);
    }
}
