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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;

class DocumentationTest extends TestCase
{
    /**
     * @dataProvider provideCommandCases
     */
    public function testCommand(Command $command): void
    {
        static $docContent = null;
        if ($docContent === null) {
            $docContent = file_get_contents(__DIR__ . '/../../../doc/03-cli.md');
        }

        self::assertStringContainsString(
            sprintf(
                "\n## %s\n\n",
                $this->getCommandName($command)
                // TODO: test description
                // TODO: test options
            ),
            $docContent
        );
    }

    private function getCommandName(Command $command): string
    {
        $name = (string) $command->getName();
        foreach ($command->getAliases() as $alias) {
            $name .= ' / ' . $alias;
        }

        return $name;
    }

    public function provideCommandCases(): \Generator
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $description = new ApplicationDescription($application);

        foreach ($description->getCommands() as $command) {
            if (in_array($command->getName(), ['about', 'completion', 'list'], true)) {
                continue;
            }
            yield [$command];
        }
    }
}
