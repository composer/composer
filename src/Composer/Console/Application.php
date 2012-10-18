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

namespace Composer\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Composer\Command;
use Composer\Command\Helper\DialogHelper;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\Util\ErrorHandler;

/**
 * The console application that handles the commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class Application extends BaseApplication
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    public function __construct()
    {
        ErrorHandler::register();
        if (function_exists('ini_set')) {
            ini_set('xdebug.show_exception_trace', false);
            ini_set('xdebug.scream', false);
        }

        parent::__construct('Composer', Composer::VERSION);
    }

    /**
     * {@inheritDoc}
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $output) {
            $styles['highlight'] = new OutputFormatterStyle('red');
            $styles['warning'] = new OutputFormatterStyle('black', 'yellow');
            $formatter = new OutputFormatter(null, $styles);
            $output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, null, $formatter);
        }

        return parent::run($input, $output);
    }

    /**
     * {@inheritDoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->io = new ConsoleIO($input, $output, $this->getHelperSet());

        if (version_compare(PHP_VERSION, '5.3.2', '<')) {
            $output->writeln('<warning>Composer only officially supports PHP 5.3.2 and above, you will most likely encounter problems with your PHP '.PHP_VERSION.', upgrading is strongly recommended.</warning>');
        }

        if (defined('COMPOSER_DEV_WARNING_TIME') && $this->getCommandName($input) !== 'self-update') {
            if (time() > COMPOSER_DEV_WARNING_TIME) {
                $output->writeln(sprintf('<warning>This dev build of composer is outdated, please run "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF']));
            }
        }

        if ($input->hasParameterOption('--profile')) {
            $startTime = microtime(true);
        }

        $oldWorkingDir = getcwd();
        $this->switchWorkingDir($input);

        $result = parent::doRun($input, $output);

        chdir($oldWorkingDir);

        if (isset($startTime)) {
            $output->writeln('<info>Memory usage: '.round(memory_get_usage() / 1024 / 1024, 2).'MB (peak: '.round(memory_get_peak_usage() / 1024 / 1024, 2).'MB), time: '.round(microtime(true) - $startTime, 2).'s');
        }

        return $result;
    }

    /**
     * @param  InputInterface    $input
     * @throws \RuntimeException
     */
    private function switchWorkingDir(InputInterface $input)
    {
        $workingDir = $input->getParameterOption(array('--working-dir', '-d'), getcwd());
        if (!is_dir($workingDir)) {
            throw new \RuntimeException('Invalid working directory specified.');
        }
        chdir($workingDir);
    }

    /**
     * @param  bool               $required
     * @return \Composer\Composer
     */
    public function getComposer($required = true)
    {
        if (null === $this->composer) {
            try {
                $this->composer = Factory::create($this->io);
            } catch (\InvalidArgumentException $e) {
                if ($required) {
                    $this->io->write($e->getMessage());
                    exit(1);
                }
            }
        }

        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    public function getIO()
    {
        return $this->io;
    }

    /**
     * Initializes all the composer commands
     */
    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new Command\AboutCommand();
        $commands[] = new Command\ConfigCommand();
        $commands[] = new Command\DependsCommand();
        $commands[] = new Command\InitCommand();
        $commands[] = new Command\InstallCommand();
        $commands[] = new Command\CreateProjectCommand();
        $commands[] = new Command\UpdateCommand();
        $commands[] = new Command\SearchCommand();
        $commands[] = new Command\ValidateCommand();
        $commands[] = new Command\ShowCommand();
        $commands[] = new Command\RequireCommand();
        $commands[] = new Command\DumpAutoloadCommand();
        $commands[] = new Command\StatusCommand();

        if ('phar:' === substr(__FILE__, 0, 5)) {
            $commands[] = new Command\SelfUpdateCommand();
        }

        return $commands;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultInputDefinition()
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption('--profile', null, InputOption::VALUE_NONE, 'Display timing and memory usage information'));
        $definition->addOption(new InputOption('--working-dir', '-d', InputOption::VALUE_REQUIRED, 'If specified, use the given directory as working directory.'));

        return $definition;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultHelperSet()
    {
        $helperSet = parent::getDefaultHelperSet();

        $helperSet->set(new DialogHelper());

        return $helperSet;
    }
}
