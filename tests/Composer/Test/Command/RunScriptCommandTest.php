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

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event as ScriptEvent;
use Composer\Test\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

class RunScriptCommandTest extends TestCase
{
    /**
     * @dataProvider getDevOptions
     */
    public function testDetectAndPassDevModeToEventAndToDispatching(bool $dev, bool $noDev): void
    {
        $scriptName = 'testScript';

        $input = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $input
            ->method('getOption')
            ->will($this->returnValueMap([
                ['list', false],
                ['dev', $dev],
                ['no-dev', $noDev],
            ]));

        $input
            ->method('getArgument')
            ->will($this->returnValueMap([
                ['script', $scriptName],
                ['args', []],
            ]));
        $input
            ->method('hasArgument')
            ->with('command')
            ->willReturn(false);
        $input
            ->method('isInteractive')
            ->willReturn(false);

        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();

        $expectedDevMode = $dev || !$noDev;

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $ed->expects($this->once())
            ->method('hasEventListeners')
            ->with($this->callback(static function (ScriptEvent $event) use ($scriptName, $expectedDevMode): bool {
                return $event->getName() === $scriptName
                && $event->isDevMode() === $expectedDevMode;
            }))
            ->willReturn(true);

        $ed->expects($this->once())
            ->method('dispatchScript')
            ->with($scriptName, $expectedDevMode, [])
            ->willReturn(0);

        $composer = $this->createComposerInstance();
        $composer->setEventDispatcher($ed);

        $command = $this->getMockBuilder('Composer\Command\RunScriptCommand')
            ->onlyMethods([
                'mergeApplicationDefinition',
                'getSynopsis',
                'initialize',
                'requireComposer',
            ])
            ->getMock();
        $command->expects($this->any())->method('requireComposer')->willReturn($composer);

        $command->run($input, $output);
    }

    public function testCanListScripts(): void
    {
        $this->initTempComposer([
            'scripts' => [
                'test' => '@php test',
                'fix-cs' => 'php-cs-fixer fix',
            ],
            'scripts-descriptions' => [
                'fix-cs' => 'Run the codestyle fixer',
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'run-script', '--list' => true]);

        $appTester->assertCommandIsSuccessful();

        $output = $appTester->getDisplay();

        self::assertStringContainsString('Runs the test script as defined in composer.json', $output, 'The default description for the test script should be printed');
        self::assertStringContainsString('Run the codestyle fixer', $output, 'The custom description for the fix-cs script should be printed');
    }

    public function testCanDefineAliases(): void
    {
        $expectedAliases = ['one', 'two', 'three'];

        $this->initTempComposer([
            'scripts' => [
                'test' => '@php test',
            ],
            'scripts-aliases' => [
                'test' => $expectedAliases,
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'test', '--help' => true, '--format' => 'json']);

        $appTester->assertCommandIsSuccessful();

        $output = $appTester->getDisplay();
        $array = json_decode($output, true);
        $actualAliases = $array['usage'];
        array_shift($actualAliases);

        self::assertSame($expectedAliases, $actualAliases, 'The custom aliases for the test command should be printed');
    }

    public function testExecutionOfSimpleSymfonyCommand(): void
    {
        $description = 'Sample description for test command';
        $this->initTempComposer([
            'scripts' => [
                'test-direct' => 'Test\\MyCommand',
                'test-ref' => ['@test-direct --inneropt innerarg'],
            ],
            'scripts-descriptions' => [
                'test-direct' => $description
            ],
            'autoload' => [
                'psr-4' => [
                    'Test\\' => '',
                ],
            ],
        ]);

        file_put_contents('MyCommand.php', <<<'TEST'
<?php

namespace Test;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

class MyCommand extends Command
{
    protected function configure(): void
    {
        $this->setDefinition([
            new InputArgument('req-arg', InputArgument::REQUIRED, 'Required arg.'),
            new InputArgument('opt-arg', InputArgument::OPTIONAL, 'Optional arg.'),
            new InputOption('inneropt', null, InputOption::VALUE_NONE, 'Option.'),
            new InputOption('outeropt', null, InputOption::VALUE_OPTIONAL, 'Optional option.'),
        ]);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($input->getArgument('req-arg'));
        $output->writeln((string) $input->getArgument('opt-arg'));
        $output->writeln('inneropt: '.($input->getOption('inneropt') ? 'set' : 'unset'));
        $output->writeln('outeropt: '.($input->getOption('outeropt') ? 'set' : 'unset'));

        return 2;
    }
}

TEST
);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'test-direct', '--outeropt' => true, 'req-arg' => 'lala']);

        self::assertSame('lala

inneropt: unset
outeropt: set
', $appTester->getDisplay(true));
        self::assertSame(2, $appTester->getStatusCode());

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'test-ref', '--outeropt' => true, 'req-arg' => 'lala']);

        self::assertSame('innerarg
lala
inneropt: set
outeropt: set
', $appTester->getDisplay(true));
        self::assertSame(2, $appTester->getStatusCode());

        //check if the description from composer.json is correctly shown
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'run-script', '--list' => true]);
        $appTester->assertCommandIsSuccessful();
        $output = $appTester->getDisplay();
        self::assertStringContainsString($description, $output, 'The contents of scripts-description for the test script should be printed');
    }

    public function testExecutionOfSymfonyCommandWithConfiguration(): void
    {
        $wrongName = 'dummy';
        $cmdName = 'custom-cmd-123';
        $cmdDesc = 'This is a Symfony command with custom configuration';

        $this->initTempComposer([
            'scripts' => [
                $wrongName => 'Test\\MyCommandWithDefinitions',
            ],
            'scripts-descriptions' => [
                $wrongName => 'dummy description',
                $cmdName => 'this should be ignored',
            ],
            'autoload' => [
                'psr-4' => [
                    'Test\\' => '',
                ],
            ],
        ]);

        file_put_contents('MyCommandWithDefinitions.php', <<<TEST
<?php

namespace Test;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

class MyCommandWithDefinitions extends Command
{
    protected static \$defaultName = "$cmdName"; //same as calling setName()
    protected static \$defaultDescription = "$cmdDesc"; //same as calling setDescription()

    protected function configure(): void
    {
        \$this->setDefinition([new InputArgument('req-arg', InputArgument::REQUIRED, 'Required arg.')]);
    }

    public function execute(InputInterface \$input, OutputInterface \$output): int
    {
        \$output->writeln(\$input->getArgument('req-arg'));
        return Command::SUCCESS;
    }
}

TEST
);

        //makes sure the command executes with the name defined inside its `configure()`...
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => $cmdName, 'req-arg' => 'lala']);
        self::assertSame("lala\n", $appTester->getDisplay(true));

        //...not in composer.scripts...
        $this->expectException(CommandNotFoundException::class);
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => $wrongName, 'req-arg' => 'lala']);

        //...and also uses its own description, instead of the one in composer.scripts-descriptions
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'run-script', '--list' => true]);
        $appTester->assertCommandIsSuccessful();
        $output = $appTester->getDisplay();
        self::assertStringContainsString($cmdDesc, $output, 'The custom description for the test script should be printed');
        self::assertStringNotContainsString($wrongName, $output, 'The dummy script shouldn\'t show');
    }

    /** @return bool[][] **/
    public static function getDevOptions(): array
    {
        return [
            [true, true],
            [true, false],
            [false, true],
            [false, false],
        ];
    }

    /** @return Composer **/
    private function createComposerInstance(): Composer
    {
        $composer = new Composer;
        $config = new Config;
        $composer->setConfig($config);

        return $composer;
    }
}
