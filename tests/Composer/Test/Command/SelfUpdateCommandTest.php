<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Command;

use Composer\Command\SelfUpdateCommand;
use Composer\Composer;
use Composer\Test\TestCase;
use Symfony\Component\Process\Process;

/**
 * @group slow
 * @depends Composer\Test\AllFunctionalTest::testBuildPhar
 */
class SelfUpdateCommandTest extends TestCase
{
    /**
     * @var string
     */
    private $phar;

    /**
     * @var string
     */
    private $home;

    public function setUp(): void
    {
        parent::setUp();

        $dir = $this->initTempComposer();
        copy(__DIR__.'/../../../composer-test.phar', $dir.'/composer.phar');
        $this->phar = $dir.'/composer.phar';
        // initTempComposer() points COMPOSER_HOME (and thus data-dir) here, which the phar subprocess inherits
        $this->home = $dir.'/composer-home';
    }

    public function testSuccessfulUpdate(): void
    {
        if (Composer::VERSION !== '@package_version'.'@') {
            $this->markTestSkipped('On releases this test can fail to upgrade as we are already on latest version');
        }

        $appTester = new Process([PHP_BINARY, $this->phar, 'self-update']);
        $status = $appTester->run();
        self::assertSame(0, $status, $appTester->getErrorOutput());

        self::assertStringContainsString('Upgrading to version', $appTester->getOutput());
    }

    public function testUpdateToSpecificVersion(): void
    {
        $appTester = new Process([PHP_BINARY, $this->phar, 'self-update', '2.4.0']);
        $status = $appTester->run();
        self::assertSame(0, $status, $appTester->getErrorOutput());

        self::assertStringContainsString('Upgrading to version 2.4.0', $appTester->getOutput());
    }

    public function testUpdateWithInvalidOptionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "invalid-option" argument does not exist.');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'invalid-option' => true]);
    }

    /**
     * @dataProvider channelOptions
     */
    public function testUpdateToDifferentChannel(string $option, string $expectedOutput): void
    {
        if (Composer::VERSION !== '@package_version'.'@' && in_array($option, ['--stable', '--preview'], true)) {
            $this->markTestSkipped('On releases this test can fail to upgrade as we are already on latest version');
        }

        $appTester = new Process([PHP_BINARY, $this->phar, 'self-update', $option]);
        $output = '';
        $status = $appTester->run(function ($type, $data) use (&$output) {
            $output .= $data;
        });
        self::assertSame(0, $status, $output);

        self::assertStringContainsString('Upgrading to version', $output);
        self::assertStringContainsString($expectedOutput, $output);
    }

    /**
     * @return array<array<string>>
     */
    public function channelOptions(): array
    {
        return [
            ['--stable', 'stable channel'],
            ['--preview', 'preview channel'],
            ['--snapshot', 'snapshot channel'],
        ];
    }

    public function testRollbackRefusesTamperedTaggedBackup(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('The openssl extension is required to verify phar signatures');
        }

        // Plant a backup named as the 2.4.0 release but whose contents are not the genuine 2.4.0 phar,
        // simulating a malicious backup dropped into a writable data-dir. The published 2.4.0 signature
        // is downloaded and must not match these contents, so the rollback has to be refused.
        @mkdir($this->home, 0777, true);
        copy($this->phar, $this->home.'/2024-01-01_00-00-00-2.4.0'.'-old.phar');

        $appTester = new Process([PHP_BINARY, $this->phar, 'self-update', '--rollback', '--no-interaction']);
        $output = '';
        $status = $appTester->run(function ($type, $data) use (&$output) {
            $output .= $data;
        });

        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('The phar signature did not match', $output);
    }

    public function testRollbackToSnapshotBackupWarnsButProceeds(): void
    {
        // Snapshot/dev backups (7-char commit sha) have no published signature, so verification is skipped
        // with a warning; in non-interactive mode it proceeds (interactively it would prompt for confirmation).
        @mkdir($this->home, 0777, true);
        copy($this->phar, $this->home.'/2024-01-01_00-00-00-abc1234'.'-old.phar');

        $appTester = new Process([PHP_BINARY, $this->phar, 'self-update', '--rollback', '--no-interaction']);
        $output = '';
        $status = $appTester->run(function ($type, $data) use (&$output) {
            $output .= $data;
        });

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('no signature is published for snapshot/dev builds', $output);
    }

    /**
     * @dataProvider backupVersionProvider
     * @param array{0: string, 1: bool} $expected
     */
    public function testParseBackupVersion(string $rollbackVersion, array $expected): void
    {
        $method = new \ReflectionMethod(SelfUpdateCommand::class, 'parseBackupVersion');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke(new SelfUpdateCommand(), $rollbackVersion));
    }

    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: bool}}>
     */
    public function backupVersionProvider(): array
    {
        return [
            'tagged release' => ['2024-06-15_10-30-45-2.5.0', ['2.5.0', true]],
            'tag with stability suffix' => ['2024-06-15_10-30-45-2.4.0-RC1', ['2.4.0-RC1', true]],
            'snapshot/dev sha' => ['2024-06-15_10-30-45-a1b2c3d', ['a1b2c3d', false]],
            'unrecognised/legacy name' => ['totally-unexpected', ['totally-unexpected', false]],
        ];
    }
}
