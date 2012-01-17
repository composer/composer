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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Finder\Finder;
use Composer\Command;
use Composer\Composer;
use Composer\Factory;

/**
 * The console application that handles the commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Application extends BaseApplication
{
    protected $composer;

    public function __construct()
    {
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
        $this->registerCommands();

        return parent::doRun($input, $output);
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        if (null === $this->composer) {
            try {
                $this->composer = Factory::create();
            } catch (\InvalidArgumentException $e) {
                echo $e->getMessage().PHP_EOL;
                exit(1);
            }
        }

        return $this->composer;
    }

    /**
     * Initializes all the composer commands
     */
    protected function registerCommands()
    {
        $this->add(new Command\AboutCommand());
        $this->add(new Command\DependsCommand());
        $this->add(new Command\InstallCommand());
        $this->add(new Command\UpdateCommand());
        $this->add(new Command\DebugPackagesCommand());
        $this->add(new Command\SearchCommand());
        $this->add(new Command\ValidateCommand());
        $this->add(new Command\ShowCommand());

        if ('phar:' === substr(__FILE__, 0, 5)) {
            $this->add(new Command\SelfUpdateCommand());
        }
    }
}
