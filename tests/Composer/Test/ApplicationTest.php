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

namespace Composer\Test;

use Composer\Console\Application;
use Composer\Util\Platform;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Platform::clearEnv('COMPOSER_DISABLE_XDEBUG_WARN');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Platform::putEnv('COMPOSER_DISABLE_XDEBUG_WARN', '1');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDevWarning(): void
    {
        $application = new Application;

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $output = new BufferedOutput();
        $application->doRun(new ArrayInput(['command' => 'about']), $output);

        $expectedOutput = sprintf('<warning>Warning: This development build of Composer is over 60 days old. It is recommended to update it by running "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF']).PHP_EOL;
        self::assertStringContainsString($expectedOutput, $output->fetch());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDevWarningSuppressedForSelfUpdate(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Does not run on windows');
        }

        $application = new Application;
        $application->add(new \Composer\Command\SelfUpdateCommand);

        if (!defined('COMPOSER_DEV_WARNING_TIME')) {
            define('COMPOSER_DEV_WARNING_TIME', time() - 1);
        }

        $output = new BufferedOutput();
        $application->doRun(new ArrayInput(['command' => 'self-update']), $output);

        self::assertSame(
            'This instance of Composer does not have the self-update command.'.PHP_EOL.
            'This could be due to a number of reasons, such as Composer being installed as a system package on your OS, or Composer being installed as a package in the current project.'.PHP_EOL,
            $output->fetch()
        );
    }

    /**
     * @runInSeparateProcess
     * @see https://github.com/composer/composer/issues/12107
     */
    public function testProcessIsolationWorksMultipleTimes(): void
    {
        $application = new Application;
        $application->add(new \Composer\Command\AboutCommand);
        self::assertSame(0, $application->doRun(new ArrayInput(['command' => 'about']), new BufferedOutput()));
        self::assertSame(0, $application->doRun(new ArrayInput(['command' => 'about']), new BufferedOutput()));
    }
}
